<?php

declare(strict_types=1);

namespace App\Http\Controllers\Dashboard;

use App\Http\Controllers\Controller;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\Stock;
use App\Models\StockLedger;
use App\Models\Store;
use App\Models\Warehouse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

class StockController extends Controller
{
    private const LOW_STOCK_THRESHOLD = 10;

    /** id => name for the active store's sellable warehouses (set per request). */
    private Collection $warehouseNames;

    /** The warehouse the current view is scoped to, or null for "all". */
    private ?string $warehouseFilter = null;

    public function index(Request $request): Response
    {
        $store = $request->user()->getActiveStore();

        if ($store === null) {
            return Inertia::render('Dashboard/Stock', [
                'products'           => ['data' => [], 'links' => []],
                'stats'              => ['total_products' => 0, 'low_stock_count' => 0, 'total_stock_value' => 0.0],
                'lowStockThreshold'  => self::LOW_STOCK_THRESHOLD,
                'warehouses'         => [],
                'primaryWarehouseId' => null,
                'filters'            => ['search' => null, 'low_stock' => false, 'warehouse' => null],
            ]);
        }

        // The store's sellable warehouses back the filter dropdown AND the
        // per-warehouse breakdown. Damaged/quarantine are excluded — they're
        // reported separately as damaged_stock, never counted as "on hand".
        $sellable = $store->warehouses()
            ->where('warehouses.type', Warehouse::TYPE_STANDARD)
            ->orderBy('name')
            ->get(['warehouses.id', 'name']);

        $this->warehouseNames = $sellable->pluck('name', 'id');
        $sellableIds          = $sellable->pluck('id')->all();

        // Validate the requested warehouse belongs to the store, else "all".
        $warehouseId = $request->input('warehouse');
        $this->warehouseFilter = in_array($warehouseId, $sellableIds, true) ? $warehouseId : null;

        $filters = [
            'search'    => $request->input('search'),
            'low_stock' => $request->boolean('low_stock'),
            'warehouse' => $this->warehouseFilter,
        ];

        $paginator = Product::query()
            ->where('store_id', $store->id)
            ->withSellableStock()
            ->when($this->warehouseFilter, fn ($q) => $q->withSum(
                ['stocks as warehouse_stock' => fn ($s) => $s->where('warehouse_id', $this->warehouseFilter)],
                'quantity',
            ))
            ->with($this->variantEagerLoads())
            ->when($request->filled('search'), function ($q) use ($filters) {
                $term = '%' . $filters['search'] . '%';
                $q->where(fn ($q) => $q->where('name', 'like', $term)->orWhere('sku', 'like', $term));
            })
            // A warehouse filter narrows the list to products actually stocked there.
            ->when($this->warehouseFilter, fn ($q) => $q->whereHas(
                'stocks', fn ($s) => $s->where('warehouse_id', $this->warehouseFilter),
            ))
            ->when($filters['low_stock'], fn ($q) => $q->having(
                $this->warehouseFilter ? 'warehouse_stock' : 'total_stock', '<=', self::LOW_STOCK_THRESHOLD,
            ))
            ->orderBy('name')
            ->paginate(20)
            ->withQueryString();

        // One query builds the whole page's per-warehouse breakdown (no N+1).
        $ids        = collect($paginator->items())->pluck('id')->all();
        $breakdowns = $this->loadBreakdowns($ids, $sellableIds);

        $paginator->through(fn (Product $p) => $this->presentProduct($p, $breakdowns[$p->id] ?? []));

        return Inertia::render('Dashboard/Stock', [
            'products'           => $paginator,
            'stats'              => $this->computeStats($store->id, $sellableIds),
            'lowStockThreshold'  => self::LOW_STOCK_THRESHOLD,
            'warehouses'         => $sellable->map(fn ($w) => ['id' => $w->id, 'name' => $w->name])->values(),
            'primaryWarehouseId' => $store->getPrimaryWarehouse()->id,
            'filters'            => $filters,
        ]);
    }

    public function adjustStock(Request $request, Product $product): RedirectResponse
    {
        // TODO: Gate::authorize('update', $product) once ProductPolicy exists.
        $store = $request->user()->getActiveStore();
        abort_if($store === null || $product->store_id !== $store->id, 403);

        $validated = $request->validate([
            // 'delta' (default): each row carries a signed `quantity_change`.
            // 'set': each row carries an absolute target `quantity`; the delta is
            // computed server-side against the locked row so a stale snapshot in
            // the browser can't over/under-shoot.
            'mode'                          => ['nullable', 'in:delta,set'],
            // 'transfer' is intentionally excluded — moving stock between locations
            // is handled by the dedicated Stock Transfer module, not the quick modal.
            'reason'                        => ['required', 'in:adjustment,damage,return'],
            'notes'                         => ['nullable', 'string', 'max:1000'],
            // The warehouse this adjustment lands in. Defaults to the primary
            // warehouse; the dashboard sends the one the view is scoped to so a
            // per-location adjustment hits the right stock row.
            'warehouse_id'                  => ['nullable', 'string'],
            'adjustments'                   => ['required', 'array', 'min:1'],
            'adjustments.*.variant_id'      => ['nullable', 'string'],
            'adjustments.*.quantity_change' => ['nullable', 'integer'],
            'adjustments.*.quantity'        => ['nullable', 'integer', 'min:0'],
        ]);

        $mode      = $validated['mode'] ?? 'delta';
        $warehouse = $this->resolveAdjustWarehouse($store, $validated['warehouse_id'] ?? null);
        abort_if($warehouse === null, 422, 'Store has no warehouse configured.');

        $isVariable = $product->isVariable();
        $variantIds = $isVariable ? $product->variants()->pluck('id')->all() : [];

        // Normalise each row to { variant_id, amount } where `amount` is either the
        // signed delta (delta mode) or the absolute target (set mode). Delta rows
        // that move nothing are dropped up front; set no-ops can only be known once
        // the current level is locked, so they're filtered inside applyAdjustment.
        $rows = collect($validated['adjustments'])
            ->map(fn ($row) => [
                'variant_id' => $row['variant_id'] ?? null,
                'amount'     => $mode === 'set'
                    ? ($row['quantity'] ?? null)
                    : ($row['quantity_change'] ?? null),
            ])
            ->filter(fn ($row) => $row['amount'] !== null)
            ->when($mode === 'delta', fn ($rows) => $rows->filter(fn ($row) => (int) $row['amount'] !== 0))
            ->values();

        if ($rows->isEmpty()) {
            throw ValidationException::withMessages([
                'adjustments' => 'Enter a quantity for at least one row.',
            ]);
        }

        foreach ($rows as $i => $row) {
            $variantId = $row['variant_id'];

            if ($isVariable && ($variantId === null || ! in_array($variantId, $variantIds, true))) {
                throw ValidationException::withMessages([
                    "adjustments.$i.variant_id" => 'Choose a valid variant for this product.',
                ]);
            }

            if (! $isVariable && $variantId !== null) {
                throw ValidationException::withMessages([
                    "adjustments.$i.variant_id" => 'This product has no variants.',
                ]);
            }
        }

        $applied = 0;

        DB::transaction(function () use ($rows, $product, $store, $warehouse, $validated, $request, $mode, &$applied) {
            foreach ($rows as $row) {
                $applied += $this->applyAdjustment(
                    product:   $product,
                    store:     $store,
                    warehouse: $warehouse,
                    variantId: $row['variant_id'],
                    mode:      $mode,
                    amount:    (int) $row['amount'],
                    reason:    $validated['reason'],
                    notes:     $validated['notes'] ?? null,
                    userId:    $request->user()->id,
                ) ? 1 : 0;
            }
        });

        if ($applied === 0) {
            return back()->with('warning', 'No changes were needed — stock already at those levels.');
        }

        return back()->with('success', $applied > 1 ? "Stock updated for {$applied} variants" : 'Stock updated');
    }

    /**
     * Apply a single adjustment to the exact (product, variant, warehouse) row and
     * write the matching ledger entry. Runs inside the caller's transaction.
     *
     * `$amount` is a signed delta in 'delta' mode, or an absolute target in 'set'
     * mode (the delta is then computed against the locked current level). Returns
     * true when stock actually moved — a set that matches the current level is a
     * no-op and writes no ledger row.
     */
    private function applyAdjustment(
        Product $product,
        $store,
        Warehouse $warehouse,
        ?string $variantId,
        string $mode,
        int $amount,
        string $reason,
        ?string $notes,
        string $userId,
    ): bool {
        $stock = Stock::firstOrCreate(
            [
                'product_id'   => $product->id,
                'variant_id'   => $variantId,
                'warehouse_id' => $warehouse->id,
            ],
            ['quantity' => 0],
        );

        // Lock the row so two concurrent adjustments can't both read the same
        // `before` and clobber each other's delta.
        $stock = Stock::query()->lockForUpdate()->find($stock->id);

        $before = (int) $stock->quantity;
        $change = $mode === 'set' ? $amount - $before : $amount;

        if ($change === 0) {
            return false;
        }

        $after = $before + $change;

        $stock->update(['quantity' => $after]);

        StockLedger::create([
            'store_id'        => $store->id,
            'product_id'      => $product->id,
            'variant_id'      => $variantId,
            'type'            => $reason,
            'quantity_change' => $change,
            'stock_before'    => $before,
            'stock_after'     => $after,
            'notes'           => $notes,
            'user_id'         => $userId,
        ]);

        return true;
    }

    public function movements(Request $request): Response
    {
        $store = $request->user()->getActiveStore();

        if ($store === null) {
            return Inertia::render('Dashboard/StockMovements', [
                'movements' => ['data' => [], 'links' => []],
            ]);
        }

        $movements = StockLedger::query()
            ->where('store_id', $store->id)
            ->with(['product:id,name,sku', 'variant:id,name,sku', 'user:id,name'])
            ->orderByDesc('created_at')
            ->paginate(20)
            ->withQueryString();

        return Inertia::render('Dashboard/StockMovements', [
            'movements' => $movements,
        ]);
    }

    /**
     * Eager-load each product's variants with the attribute values needed for
     * display names. Per-warehouse quantities come from loadBreakdowns(), not a
     * withSum here, so the card, breakdown, and adjust modal all share one source.
     *
     * @return array<string, mixed>
     */
    private function variantEagerLoads(): array
    {
        return [
            'variants' => fn ($q) => $q->with('attributeValues.attribute'),
        ];
    }

    /**
     * Per-warehouse stock breakdown for a page of products, in ONE query.
     *
     * Sums are variant-aware to match the card headline: a variable product's
     * stock is its VARIANT rows (the legacy product-level `variant_id = null` row
     * is ignored); a simple product's stock is its null-variant rows.
     *
     * @param  array<int, string>  $productIds
     * @param  array<int, string>  $sellableIds
     * @return array<string, array{simpleWh: array<string,int>, variantWh: array<string,int>, byVariant: array<string, array<string,int>>}>
     */
    private function loadBreakdowns(array $productIds, array $sellableIds): array
    {
        if ($productIds === [] || $sellableIds === []) {
            return [];
        }

        $rows = Stock::query()
            ->whereIn('product_id', $productIds)
            ->whereIn('warehouse_id', $sellableIds)
            ->get(['product_id', 'variant_id', 'warehouse_id', 'quantity']);

        $out = [];

        foreach ($rows as $row) {
            $pid = $row->product_id;
            $wid = $row->warehouse_id;
            $qty = (int) $row->quantity;

            $out[$pid] ??= ['simpleWh' => [], 'variantWh' => [], 'byVariant' => []];

            if ($row->variant_id === null) {
                $out[$pid]['simpleWh'][$wid] = ($out[$pid]['simpleWh'][$wid] ?? 0) + $qty;
            } else {
                $out[$pid]['variantWh'][$wid]                     = ($out[$pid]['variantWh'][$wid] ?? 0) + $qty;
                $out[$pid]['byVariant'][$row->variant_id][$wid]   = ($out[$pid]['byVariant'][$row->variant_id][$wid] ?? 0) + $qty;
            }
        }

        return $out;
    }

    /**
     * Turn a {warehouseId => qty} map into a display list, dropping zero rows,
     * respecting the active warehouse filter, and sorting by quantity desc.
     *
     * @param  array<string, int>  $byWarehouse
     * @return array<int, array{warehouse_id: string, name: string, quantity: int}>
     */
    private function breakdownList(array $byWarehouse): array
    {
        $list = [];

        foreach ($byWarehouse as $wid => $qty) {
            if ($this->warehouseFilter !== null && $wid !== $this->warehouseFilter) {
                continue;
            }
            if ((int) $qty === 0) {
                continue;
            }
            $list[] = [
                'warehouse_id' => $wid,
                'name'         => $this->warehouseNames[$wid] ?? 'Warehouse',
                'quantity'     => (int) $qty,
            ];
        }

        usort($list, fn ($a, $b) => $b['quantity'] <=> $a['quantity']);

        return $list;
    }

    /** Effective headline total for a {warehouseId => qty} map given the filter. */
    private function effectiveTotal(array $byWarehouse): int
    {
        if ($this->warehouseFilter !== null) {
            return (int) ($byWarehouse[$this->warehouseFilter] ?? 0);
        }

        return array_sum(array_map('intval', $byWarehouse));
    }

    /**
     * @param  array<string, mixed>  $breakdown
     * @return array<string, mixed>
     */
    private function presentProduct(Product $product, array $breakdown): array
    {
        $hasVariants = $product->isVariable() && $product->variants->isNotEmpty();

        $simpleWh  = $breakdown['simpleWh']  ?? [];
        $variantWh = $breakdown['variantWh'] ?? [];
        $byVariant = $breakdown['byVariant'] ?? [];

        $productWh = $hasVariants ? $variantWh : $simpleWh;

        $variants = $hasVariants
            ? $product->variants->map(function (ProductVariant $v) use ($byVariant) {
                $vwh = $byVariant[$v->id] ?? [];

                return [
                    'id'        => $v->id,
                    'name'      => $v->getDisplayName(),
                    'sku'       => $v->sku,
                    'stock'     => $this->effectiveTotal($vwh),
                    'breakdown' => $this->breakdownList($vwh),
                ];
            })->values()->all()
            : [];

        return [
            'id'              => $product->id,
            'name'            => $product->name,
            'sku'             => $product->sku,
            'price'           => (float) $product->price,
            'category'        => $product->category ?? null,
            'type'            => $product->type,
            'total_stock'     => $this->effectiveTotal($productWh),
            'damaged_stock'   => (int) ($product->damaged_stock ?? 0),
            'has_variants'    => $hasVariants,
            'variant_count'   => $hasVariants ? $product->variants->count() : 0,
            'warehouse_count' => count($this->breakdownList($productWh)),
            'breakdown'       => $this->breakdownList($productWh),
            'variants'        => $variants,
        ];
    }

    /**
     * Dashboard stat tiles, scoped to the active warehouse filter. Effective
     * per-product stock is variant-aware (mirrors the cards) and computed from a
     * single stock-rows load rather than N per-product aggregates.
     *
     * @param  array<int, string>  $sellableIds
     * @return array<string, mixed>
     */
    private function computeStats(string $storeId, array $sellableIds): array
    {
        $products = Product::query()->where('store_id', $storeId)->get(['id', 'price', 'type']);

        if ($products->isEmpty() || $sellableIds === []) {
            return ['total_products' => 0, 'low_stock_count' => 0, 'total_stock_value' => 0.0];
        }

        $rows = Stock::query()
            ->whereIn('product_id', $products->pluck('id'))
            ->whereIn('warehouse_id', $sellableIds)
            ->when($this->warehouseFilter, fn ($q) => $q->where('warehouse_id', $this->warehouseFilter))
            ->get(['product_id', 'variant_id', 'quantity']);

        $byProduct = [];
        foreach ($rows as $row) {
            $byProduct[$row->product_id] ??= ['variant' => 0, 'simple' => 0];
            $bucket = $row->variant_id === null ? 'simple' : 'variant';
            $byProduct[$row->product_id][$bucket] += (int) $row->quantity;
        }

        $effective = fn (Product $p): int => $p->type === 'variable'
            ? ($byProduct[$p->id]['variant'] ?? 0)
            : ($byProduct[$p->id]['simple'] ?? 0);

        // Global counts every product; a warehouse view counts only those stocked there.
        $scoped = $this->warehouseFilter !== null
            ? $products->filter(fn (Product $p) => isset($byProduct[$p->id]))
            : $products;

        return [
            'total_products'    => $scoped->count(),
            'low_stock_count'   => $scoped->filter(fn (Product $p) => $effective($p) <= self::LOW_STOCK_THRESHOLD)->count(),
            'total_stock_value' => round($scoped->reduce(fn ($carry, Product $p) => $carry + ((float) $p->price) * $effective($p), 0.0), 2),
        ];
    }

    /** Resolve the warehouse an adjustment targets: the requested one if it
     * belongs to the store, otherwise the primary. */
    private function resolveAdjustWarehouse(Store $store, ?string $warehouseId): ?Warehouse
    {
        if ($warehouseId !== null) {
            $warehouse = $store->warehouses()->where('warehouses.id', $warehouseId)->first();

            if ($warehouse !== null) {
                return $warehouse;
            }
        }

        return $store->getPrimaryWarehouse();
    }
}
