<?php

declare(strict_types=1);

namespace App\Http\Controllers\Dashboard;

use App\Http\Controllers\Controller;
use App\Models\Product;
use App\Models\Stock;
use App\Models\StockTransfer;
use App\Models\Store;
use App\Services\Pos\DocumentGenerationService;
use App\Services\Stocks\StockTransferService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response as InertiaResponse;

class StockTransferController extends Controller
{
    public function __construct(private readonly StockTransferService $transfers)
    {
    }

    public function index(Request $request): InertiaResponse
    {
        $store = $request->user()->getActiveStore();

        if ($store === null) {
            return Inertia::render('Dashboard/StockTransfers', [
                'transfers' => ['data' => [], 'links' => []],
                'stats'     => ['total' => 0, 'units_moved' => 0, 'this_month' => 0],
            ]);
        }

        $transfers = StockTransfer::query()
            ->where('store_id', $store->id)
            ->with([
                'sourceWarehouse:id,name',
                'destinationWarehouse:id,name',
                'destinationMember:id,name',
                'responsibleMember:id,name',
            ])
            ->withCount('items')
            ->orderByDesc('created_at')
            ->paginate(15)
            ->withQueryString()
            ->through(fn (StockTransfer $t) => $this->present($t));

        return Inertia::render('Dashboard/StockTransfers', [
            'transfers' => $transfers,
            'stats'     => [
                'total'       => StockTransfer::where('store_id', $store->id)->count(),
                'units_moved' => (int) StockTransfer::where('store_id', $store->id)->sum('total_quantity'),
                'this_month'  => StockTransfer::where('store_id', $store->id)
                    ->where('created_at', '>=', now()->startOfMonth())
                    ->count(),
            ],
        ]);
    }

    public function create(Request $request): InertiaResponse
    {
        $store = $request->user()->getActiveStore();
        abort_if($store === null, 403);

        // Self-heal: pull in any dashboard-created warehouses that were never
        // attached to the store pivot, so they appear in the pickers below.
        $store->attachOwnerWarehouses();

        $warehouseIds = $store->warehouses()->pluck('warehouses.id');

        $stock = Stock::query()
            ->whereIn('warehouse_id', $warehouseIds)
            ->get(['warehouse_id', 'product_id', 'variant_id', 'quantity'])
            ->map(fn (Stock $s) => [
                'warehouse_id' => $s->warehouse_id,
                'product_id'   => $s->product_id,
                'variant_id'   => $s->variant_id,
                'quantity'     => (int) $s->quantity,
            ])
            ->values();

        // Only products that actually hold stock somewhere are transferable.
        $productIds = $stock->pluck('product_id')->unique()->values();

        $products = Product::query()
            ->where('store_id', $store->id)
            ->whereIn('id', $productIds)
            ->with(['variants' => fn ($q) => $q->orderBy('name')])
            ->orderBy('name')
            ->get(['id', 'name', 'sku', 'type', 'price'])
            ->map(fn (Product $p) => [
                'id'           => $p->id,
                'name'         => $p->name,
                'sku'          => $p->sku,
                'has_variants' => $p->isVariable() && $p->variants->isNotEmpty(),
                'variants'     => $p->variants->map(fn ($v) => [
                    'id'   => $v->id,
                    'name' => $v->getDisplayName(),
                    'sku'  => $v->sku,
                ])->values(),
            ])
            ->values();

        return Inertia::render('Dashboard/StockTransferCreate', [
            'warehouses'          => $this->warehousePayload($store),
            'primaryWarehouseId'  => $store->getPrimaryWarehouse()->id,
            'products'            => $products,
            'stock'               => $stock,
            'members'             => $this->memberPayload($store),
            'today'               => now()->toDateString(),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $store = $request->user()->getActiveStore();
        abort_if($store === null, 403);

        $validated = $request->validate([
            'source_warehouse_id'      => ['required', 'string'],
            'destination_kind'         => ['required', Rule::in([
                StockTransfer::KIND_WAREHOUSE, StockTransfer::KIND_TEAM, StockTransfer::KIND_EXTERNAL,
            ])],
            'destination_warehouse_id' => ['nullable', 'string', 'required_if:destination_kind,warehouse'],
            'destination_member_id'    => ['nullable', 'string'],
            'destination_label'        => [
                'nullable', 'string', 'max:255',
                'required_if:destination_kind,team',
                'required_if:destination_kind,external',
            ],
            'responsible_member_id'    => ['nullable', 'string'],
            'transfer_date'            => ['required', 'date'],
            'notes'                    => ['nullable', 'string', 'max:2000'],
            'items'                    => ['required', 'array', 'min:1'],
            'items.*.product_id'       => ['required', 'string'],
            'items.*.variant_id'       => ['nullable', 'string'],
            'items.*.quantity'         => ['required', 'integer', 'min:1'],
        ]);

        try {
            $transfer = $this->transfers->create($store, $validated, $request->user());
        } catch (ValidationException $e) {
            // Re-throw so Inertia surfaces field errors on the create form.
            throw $e;
        }

        return redirect()
            ->route('dashboard.stock.transfers.index')
            ->with('success', "Transfer {$transfer->reference} recorded — {$transfer->total_quantity} unit(s) moved.")
            ->with('transfer_reference', $transfer->reference)
            ->with('transfer_slip_url', "/dashboard/stock/transfers/{$transfer->id}/slip");
    }

    public function slip(Request $request, StockTransfer $transfer, DocumentGenerationService $documents): Response
    {
        $store = $request->user()->getActiveStore();
        abort_if($store === null || $transfer->store_id !== $store->id, 403);

        $transfer->load([
            'items', 'store', 'sourceWarehouse', 'destinationWarehouse',
            'destinationMember', 'responsibleMember', 'createdBy',
        ]);

        $disposition = $request->boolean('download') ? 'attachment' : 'inline';

        return response($documents->renderBonDeSortie($this->slipPayload($transfer)), 200, [
            'Content-Type'        => 'application/pdf',
            'Content-Disposition' => "{$disposition}; filename=\"{$transfer->reference}.pdf\"",
        ]);
    }

    // -------------------------------------------------------------------------

    /**
     * @return array<string, mixed>
     */
    private function present(StockTransfer $t): array
    {
        return [
            'id'               => $t->id,
            'reference'        => $t->reference,
            'status'           => $t->status,
            'transfer_date'    => $t->transfer_date?->toDateString(),
            'created_at'       => $t->created_at?->toIso8601String(),
            'source'           => $t->sourceWarehouse?->name ?? '—',
            'destination'      => $t->destinationName(),
            'destination_kind' => $t->destination_kind,
            'items_count'      => (int) ($t->items_count ?? 0),
            'total_quantity'   => (int) $t->total_quantity,
            'responsible'      => $t->responsibleMember?->name,
            'slip_url'         => "/dashboard/stock/transfers/{$t->id}/slip",
        ];
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function warehousePayload(Store $store): array
    {
        return $store->warehouses()
            ->get(['warehouses.id', 'name', 'type'])
            ->map(fn ($w) => ['id' => $w->id, 'name' => $w->name, 'type' => $w->type])
            ->values()
            ->all();
    }

    /**
     * Owner + active team members, de-duplicated. Backs both the "responsible"
     * and the future "team destination" pickers.
     *
     * @return array<int, array{id: string, name: string}>
     */
    private function memberPayload(Store $store): array
    {
        $members = $store->activeMembers()->with('user:id,name')->get()
            ->pluck('user')
            ->filter();

        return collect([$store->owner])
            ->merge($members)
            ->filter()
            ->unique('id')
            ->map(fn ($u) => ['id' => $u->id, 'name' => $u->name])
            ->values()
            ->all();
    }

    /**
     * @return array<string, mixed>
     */
    private function slipPayload(StockTransfer $t): array
    {
        $currency = $t->store?->currency ?? 'MAD';

        $items = $t->items->values()->map(fn ($item, $i) => [
            'index'      => $i + 1,
            'sku'        => $item->sku,
            'product'    => $item->product_name,
            'variant'    => $item->variant_name,
            'quantity'   => (int) $item->quantity,
            'unit_price' => $item->unit_price !== null ? (float) $item->unit_price : null,
            'line_value' => $item->unit_price !== null ? (float) $item->unit_price * (int) $item->quantity : null,
        ])->all();

        $totalValue = collect($items)->sum(fn ($i) => $i['line_value'] ?? 0);

        return [
            'store'          => $t->store,
            'currency'       => strtoupper($currency),
            'reference'      => $t->reference,
            'status'         => $t->status,
            'transfer_date'  => $t->transfer_date,
            'generated_at'   => now(),
            'source'         => $t->sourceWarehouse?->name ?? '—',
            'destination'    => $t->destinationName(),
            'destination_kind' => $t->destination_kind,
            'responsible'    => $t->responsibleMember?->name,
            'created_by'     => $t->createdBy?->name,
            'items'          => $items,
            'total_quantity' => (int) $t->total_quantity,
            'total_value'    => (float) $totalValue,
            'notes'          => $t->notes,
        ];
    }
}
