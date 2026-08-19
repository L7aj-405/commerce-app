<?php

declare(strict_types=1);

namespace App\Http\Controllers\Dashboard;

use App\Http\Controllers\Controller;
use App\Models\OrderReturn;
use App\Models\OrderReturnItem;
use App\Services\Orders\ReturnInspectionService;
use App\Support\DepartmentRegistry;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The inspection department: the returns queue and the per-line worksheet where
 * goods are routed back to active stock or written off as damaged.
 */
class ReturnController extends Controller
{
    public function index(Request $request): Response
    {
        $store = $request->user()->getActiveStore();

        if ($store === null) {
            return Inertia::render('Dashboard/Orders/Returns/Index', ['store' => null, 'returns' => []]);
        }

        $returns = OrderReturn::query()
            ->where('store_id', $store->id)
            ->with(['items', 'returnable'])
            ->when(
                $request->input('status', 'open') === 'open',
                fn ($q) => $q->open(),
            )
            ->latest('flagged_at')
            ->limit(200)
            ->get()
            ->map(fn (OrderReturn $r) => $this->present($r))
            ->all();

        return Inertia::render('Dashboard/Orders/Returns/Index', [
            'store'       => ['id' => $store->id, 'name' => $store->name, 'currency' => $store->currency ?? 'MAD'],
            'returns'     => $returns,
            'filters'     => ['status' => $request->input('status', 'open')],
            'departments' => DepartmentRegistry::visibleTo($request->user(), $store),
        ]);
    }

    public function show(Request $request, string $id, ReturnInspectionService $inspections): Response
    {
        $return = $this->resolve($request, $id);

        return Inertia::render('Dashboard/Orders/Returns/Inspect', [
            'return'     => $this->present($return, detailed: true),
            'conditions' => OrderReturnItem::conditions(),
            'summary'    => $inspections->outcomeSummary($return),
        ]);
    }

    /** Record the inspector's verdict on one or more lines. */
    public function disposition(Request $request, string $id, ReturnInspectionService $inspections): RedirectResponse
    {
        $return = $this->resolve($request, $id);

        $validated = $request->validate([
            'lines'             => ['required', 'array', 'min:1'],
            'lines.*.item_id'   => ['required', 'string'],
            'lines.*.condition' => ['required', Rule::in(OrderReturnItem::conditions())],
            'lines.*.quantity'  => ['nullable', 'integer', 'min:0'],
            'lines.*.notes'     => ['nullable', 'string', 'max:500'],
        ]);

        try {
            $inspections->disposition($return, $validated['lines'], $request->user());
        } catch (ValidationException $e) {
            if ($request->expectsJson()) {
                throw $e;
            }

            return back()->with('error', $e->validator->errors()->first());
        }

        return back()->with('success', 'Inspection saved.');
    }

    /** Close a fully inspected return and finish the order's lifecycle. */
    public function close(Request $request, string $id, ReturnInspectionService $inspections): RedirectResponse
    {
        $return = $this->resolve($request, $id);

        try {
            $inspections->close($return, $request->user());
        } catch (ValidationException $e) {
            if ($request->expectsJson()) {
                throw $e;
            }

            return back()->with('error', $e->validator->errors()->first());
        }

        $summary = $inspections->outcomeSummary($return);

        return back()->with('success', sprintf(
            'Return closed — %d unit(s) restocked, %d moved to damaged stock, %d not received.',
            $summary['restocked'],
            $summary['damaged'],
            $summary['missing'],
        ));
    }

    // -------------------------------------------------------------------------

    /** Scope every lookup to the active store — a return is tenant data. */
    private function resolve(Request $request, string $id): OrderReturn
    {
        $store = $request->user()->getActiveStore();
        abort_if($store === null, 403);

        return OrderReturn::query()
            ->where('store_id', $store->id)
            ->with(['items', 'returnable', 'flaggedBy:id,name', 'inspectedBy:id,name'])
            ->findOrFail($id);
    }

    /** @return array<string, mixed> */
    private function present(OrderReturn $return, bool $detailed = false): array
    {
        $order = $return->returnable;

        $payload = [
            'id'              => $return->id,
            'reference'       => $return->reference,
            'status'          => $return->status,
            'reason'          => $return->reason,
            'notes'           => $return->notes,
            'order_reference' => $order?->receipt_number ?? $order?->order_number,
            'order_type'      => $order instanceof \App\Models\PosOrder ? 'pos' : 'online',
            'order_id'        => $order?->getKey(),
            'customer_name'   => $order?->customer_name,
            'line_count'      => $return->items->count(),
            'pending_count'   => $return->items->whereNull('condition')->count(),
            'can_close'       => $return->isFullyDispositioned() && ! $return->isClosed(),
            'flagged_at'      => $return->flagged_at?->toIso8601String(),
            'closed_at'       => $return->closed_at?->toIso8601String(),
        ];

        if (! $detailed) {
            return $payload;
        }

        return $payload + [
            'flagged_by'   => $return->flaggedBy?->name,
            'inspected_by' => $return->inspectedBy?->name,
            'inspected_at' => $return->inspected_at?->toIso8601String(),
            'items'        => $return->items->map(fn (OrderReturnItem $item) => [
                'id'                => $item->id,
                'product_name'      => $item->product_name,
                'product_sku'       => $item->product_sku,
                'quantity_ordered'  => $item->quantity_ordered,
                'quantity_returned' => $item->quantity_returned,
                'condition'         => $item->condition,
                'notes'             => $item->inspection_notes,
                'refund_amount'     => (float) $item->refund_amount,
                // An online line with no local product can be inspected but
                // moves no stock — the UI greys the destination out.
                'movable'           => $item->product_id !== null,
                'locked'            => $item->hasStockMovement(),
            ])->all(),
        ];
    }
}
