<?php

declare(strict_types=1);

namespace App\Http\Controllers\Dashboard;

use App\Enums\FulfillmentStatus;
use App\Http\Controllers\Controller;
use App\Services\Inventory\InventoryTransferService;
use App\Services\Orders\OperationsQueueService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Focused, single-station queues layered over the existing department
 * workflow — no new engine. Unlike DepartmentController these are scoped by
 * warehouse OPERATOR (OperationsQueueService), not the viewer's single active
 * store, because one warehouse can serve several client stores at once.
 *
 * Claim/release/take-next continue to live on DepartmentController — a
 * warehouse claim is identical work regardless of which of these pages linked
 * to it, so it is not duplicated here.
 */
class OperationsController extends Controller
{
    public function __construct(
        private readonly OperationsQueueService $queues,
    ) {}

    public function waitingStock(Request $request): Response
    {
        $user = $request->user();

        return Inertia::render('Dashboard/Operations/WaitingForStock', [
            'orders'            => $this->queues->waitingStockDetails($user),
            'is_agency_context' => $this->queues->isAgencyContext($user),
        ]);
    }

    public function picking(Request $request): Response
    {
        return $this->render($request, 'Picking', [FulfillmentStatus::ReadyForPicking, FulfillmentStatus::Picking, FulfillmentStatus::Confirmed, FulfillmentStatus::InProgress]);
    }

    public function packing(Request $request): Response
    {
        return $this->render($request, 'Packing', [FulfillmentStatus::Packing]);
    }

    public function readyForDelivery(Request $request): Response
    {
        return $this->render($request, 'ReadyForDelivery', [FulfillmentStatus::ReadyForDelivery]);
    }

    public function transferReceiving(Request $request): Response
    {
        $user = $request->user();

        return Inertia::render('Dashboard/Operations/TransferReceiving', [
            'transfers'         => $this->queues->transfersToReceive($user),
            'is_agency_context' => $this->queues->isAgencyContext($user),
        ]);
    }

    public function receiveTransfer(Request $request, string $transfer, InventoryTransferService $transfers): RedirectResponse
    {
        $user = $request->user();

        $model = $this->queues->findReceivableTransfer($user, $transfer);
        abort_if($model === null, 404);

        try {
            $transfers->receive($model, $user);
        } catch (ValidationException $e) {
            return back()->with('error', $e->validator->errors()->first());
        }

        return back()->with('success', "Transfer {$model->reference} received.");
    }

    /**
     * "Recheck stock" — reruns WaitingStockReallocationService for this
     * order's open shortage lines. The order itself may or may not be the
     * one that ends up resolved (FIFO is warehouse-wide, not per-order), but
     * clicking this anywhere in the queue nudges the whole line forward.
     */
    public function recheckWaitingStock(Request $request, string $type, string $id): RedirectResponse
    {
        $user = $request->user();
        $order = $this->queues->findWaitingOrder($user, $type, $id);
        abort_if($order === null, 404);

        $results = $this->queues->recheckOrder($order, $user);
        $resolved = array_sum(array_column($results, 'resolved'));
        $repaired = array_sum(array_map(fn ($r) => ($r['repaired'] ?? false) ? 1 : 0, $results));

        if ($resolved > 0) {
            $message = "Recheck resolved {$resolved} shortage line(s).";
            if ($repaired > 0) {
                $message .= " ({$repaired} line(s) had a stale inventory mapping repaired first.)";
            }

            return back()->with('success', $message);
        }

        // Never a silent no-op: every line carries a concrete reason it's
        // still blocked (insufficient stock at target, stock elsewhere but
        // no transfer, unmapped line, …) — surface it instead of a generic
        // "nothing happened".
        $reasons = array_values(array_unique(array_filter(array_column($results, 'reason'))));

        return back()->with('warning', $reasons === [] ? 'Still not enough stock available.' : implode(' ', $reasons));
    }

    /** "Request transfer" — creates a real InventoryTransfer from a warehouse that actually has the stock, or fails clearly. */
    public function requestWaitingStockTransfer(Request $request, string $type, string $id): RedirectResponse
    {
        $user = $request->user();
        $order = $this->queues->findWaitingOrder($user, $type, $id);
        abort_if($order === null, 404);

        $reservationId = $request->input('reservation_id');

        try {
            $this->queues->requestTransferForOrder($order, $user, $reservationId);
        } catch (ValidationException $e) {
            return back()->with('error', $e->validator->errors()->first());
        }

        return back()->with('success', 'Transfer requested.');
    }

    /** "Mark restock requested" — a visibility flag only; never moves stock or fabricates a transfer. */
    public function markWaitingStockRestockRequested(Request $request, string $type, string $id): RedirectResponse
    {
        $user = $request->user();
        $order = $this->queues->findWaitingOrder($user, $type, $id);
        abort_if($order === null, 404);

        $this->queues->markRestockRequested($order);

        return back()->with('success', 'Marked as restock requested.');
    }

    /** @param array<int, FulfillmentStatus> $statuses */
    private function render(Request $request, string $page, array $statuses): Response
    {
        $user = $request->user();

        return Inertia::render("Dashboard/Operations/{$page}", [
            'orders'            => $this->queues->queue($user, $statuses),
            'is_agency_context' => $this->queues->isAgencyContext($user),
        ]);
    }
}
