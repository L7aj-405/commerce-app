<?php

declare(strict_types=1);

namespace App\Http\Controllers\Dashboard;

use App\Enums\FulfillmentStatus;
use App\Http\Controllers\Controller;
use App\Models\InventoryReservation;
use App\Models\Order;
use App\Models\PosOrder;
use App\Models\Product;
use App\Models\ProductInventoryLink;
use App\Models\ProductVariant;
use App\Models\Stock;
use App\Models\StockLedger;
use App\Models\Store;
use App\Models\VariantInventoryLink;
use App\Models\Warehouse;
use App\Models\WarehouseInventoryBalance;
use App\Services\Catalog\ProductStockSnapshotService;
use App\Services\Inventory\CatalogInventoryService;
use App\Services\Inventory\InventoryEngine;
use App\Services\Sync\ProductPushService;
use Illuminate\Http\JsonResponse;
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

    /** Warehouse ids the operational (on_hand/reserved/available/waiting) numbers are aggregated across for this request. */
    private array $warehouseScope = [];

    public function __construct(
        private readonly ProductStockSnapshotService $snapshots,
        private readonly CatalogInventoryService $catalog,
        private readonly InventoryEngine $engine,
    ) {}

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
        $this->warehouseScope  = $this->warehouseFilter !== null ? [$this->warehouseFilter] : $sellableIds;

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

        // Engine-sourced numbers (on_hand/reserved/available/waiting_demand)
        // for the same page — relations are eager-loaded above so this reads
        // already-fetched WarehouseInventoryBalance rows, no per-row queries.
        // Waiting demand is the one number that genuinely needs its own
        // query (InventoryReservation isn't reachable through the product
        // relation graph); batched once for every item id on the page.
        $itemIds = collect($paginator->items())
            ->flatMap(fn (Product $p) => $p->isVariable()
                ? $p->variants->map(fn (ProductVariant $v) => $v->inventoryLink?->inventory_item_id)
                : [$p->inventoryLink?->inventory_item_id])
            ->filter()
            ->unique()
            ->values()
            ->all();
        $waitingByItemId = $this->snapshots->waitingDemandFor($itemIds, $this->warehouseScope);

        $paginator->through(fn (Product $p) => $this->presentProduct($p, $breakdowns[$p->id] ?? [], $waitingByItemId));

        return Inertia::render('Dashboard/Stock', [
            'products'           => $paginator,
            'stats'              => $this->computeStats($store->id, $sellableIds),
            'lowStockThreshold'  => self::LOW_STOCK_THRESHOLD,
            'warehouses'         => $sellable->map(fn ($w) => ['id' => $w->id, 'name' => $w->name])->values(),
            'primaryWarehouseId' => $store->getPrimaryWarehouse()->id,
            'filters'            => $filters,
        ]);
    }

    public function adjustStock(Request $request, Product $product, ProductPushService $pushService): RedirectResponse|JsonResponse
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

        // Resolve the InventoryItem for every row up front (creates it if
        // missing — exactly what the legacy Stock write's
        // InventoryCompatibilityBridge would do anyway on save) so "before"
        // balances are well-defined for the operational feedback below.
        // Never changes what applyAdjustment() itself writes.
        $variantsById = $isVariable ? $product->variants()->get()->keyBy('id') : collect();
        $pairs = []; // inventory_item_id => ['item' => InventoryItem, 'variant_id' => ?string]

        foreach ($rows as $row) {
            $variant = $row['variant_id'] !== null ? $variantsById->get($row['variant_id']) : null;
            $item    = $this->catalog->forCatalog($product, $variant);

            if ($item !== null && ! isset($pairs[$item->id])) {
                $pairs[$item->id] = ['item' => $item, 'variant_id' => $row['variant_id']];
            }
        }

        $before = [];
        foreach ($pairs as $itemId => $pair) {
            $balance = $this->engine->balance($pair['item'], $warehouse);
            $before[$itemId] = ['reserved' => (int) $balance->reserved];
        }

        $waitingOrderKeysBefore = $this->waitingOrderKeysFor(array_keys($pairs), $warehouse->id);

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
            $message = 'No changes were needed — stock already at those levels.';

            return $this->respondAdjustment($request, back()->with('warning', $message), [
                'success' => false, 'message' => $message, 'applied_count' => 0,
            ]);
        }

        // Everything below only READS the outcome — the legacy Stock
        // write's InventoryCompatibilityBridge already ran the waiting-stock
        // auto-recheck synchronously (afterCommit, sync queue) by the time
        // execution reaches here, so nothing here re-triggers a reservation.
        $waitingUnitsReserved = 0;
        $results = [];

        foreach ($pairs as $itemId => $pair) {
            $balance = $this->engine->balance($pair['item'], $warehouse);
            $waitingUnitsReserved += max(0, $balance->reserved - ($before[$itemId]['reserved'] ?? 0));

            $results[] = [
                'variant_id' => $pair['variant_id'],
                'inventory_item_id' => $pair['item']->id,
                'on_hand'   => $balance->on_hand,
                'reserved'  => $balance->reserved,
                'available' => $balance->available(),
            ];
        }

        $waitingOrdersReleased  = $this->countReleasedOrders($waitingOrderKeysBefore);
        $remainingWaitingDemand = array_sum($this->snapshots->waitingDemandFor(array_keys($pairs), [$warehouse->id]));
        $externalSync           = $this->pushExternalStock($pushService, $product, $pairs, $variantsById);

        $message = $this->composeAdjustmentMessage($applied, $waitingOrdersReleased, $waitingUnitsReserved, $remainingWaitingDemand, $results, $externalSync);

        return $this->respondAdjustment($request, back()->with('success', $message), [
            'success' => true,
            'message' => $message,
            'applied_count' => $applied,
            'waiting_orders_released' => $waitingOrdersReleased,
            'waiting_units_reserved' => $waitingUnitsReserved,
            'external_sync' => $externalSync,
            'results' => $results,
            'links' => [
                'pick_and_pack' => '/dashboard/departments/packing',
                'waiting_stock' => '/dashboard/operations/waiting-stock',
            ],
        ]);
    }

    /** JSON for an XHR/fetch caller (the Adjust Stock modal), the usual Inertia redirect-back otherwise. */
    private function respondAdjustment(Request $request, RedirectResponse $fallback, array $payload): RedirectResponse|JsonResponse
    {
        if ($request->ajax() || $request->wantsJson()) {
            return response()->json($payload);
        }

        return $fallback;
    }

    /** Distinct "morphClass:id" keys for every order with an open shortage on any of these items at this warehouse, right now. */
    private function waitingOrderKeysFor(array $inventoryItemIds, string $warehouseId): Collection
    {
        if ($inventoryItemIds === []) {
            return collect();
        }

        return InventoryReservation::withoutOrganizationTenancy(fn () => InventoryReservation::query()
            ->whereIn('inventory_item_id', $inventoryItemIds)
            ->where('warehouse_id', $warehouseId)
            ->where('shortage_quantity', '>', 0)
            ->with('allocation:id,source_type,source_id')
            ->get())
            ->pluck('allocation')
            ->filter()
            ->map(fn ($a) => $a->source_type . ':' . $a->source_id)
            ->unique()
            ->values();
    }

    /** How many of the given "morphClass:id" orders are no longer WaitingForStock. */
    private function countReleasedOrders(Collection $waitingOrderKeys): int
    {
        $posClass = (new PosOrder())->getMorphClass();
        $released = 0;

        foreach ($waitingOrderKeys as $key) {
            [$type, $id] = explode(':', $key, 2);
            $model = $type === $posClass ? PosOrder::find($id) : Order::find($id);

            if ($model !== null && $model->fulfillment_status !== FulfillmentStatus::WaitingForStock) {
                $released++;
            }
        }

        return $released;
    }

    /**
     * Pushes the new quantity to WooCommerce/Shopify for every adjusted row —
     * the same push ProductController::adjustStock already does for a single
     * variant. The bulk Stock dashboard modal never did this before; adding
     * it closes an external-sync gap without touching any inventory
     * semantics (local balances are already committed by this point).
     *
     * @param  array<string, array{item: \App\Models\InventoryItem, variant_id: ?string}>  $pairs
     */
    private function pushExternalStock(ProductPushService $pushService, Product $product, array $pairs, Collection $variantsById): string
    {
        $hasListing = false;
        $anyFailed  = false;
        $anySucceeded = false;

        foreach ($pairs as $pair) {
            $variant = $pair['variant_id'] !== null ? $variantsById->get($pair['variant_id']) : null;

            foreach (['woocommerce', 'shopify'] as $platform) {
                $result = $variant !== null
                    ? $pushService->pushVariantStock($variant, $platform)
                    : $pushService->pushStock($product, $platform);

                if (empty($result)) {
                    continue; // no listing on this platform for this row
                }

                $hasListing = true;
                $succeeded  = collect($result)->every(fn ($r) => $r['success'] ?? false);
                $anySucceeded = $anySucceeded || $succeeded;
                $anyFailed    = $anyFailed || ! $succeeded;
            }
        }

        if (! $hasListing) {
            return 'skipped';
        }

        return match (true) {
            ! $anyFailed => 'queued',
            $anySucceeded => 'partial',
            default => 'failed',
        };
    }

    /** @param array<int, array{variant_id: ?string, on_hand: int, reserved: int, available: int}> $results */
    private function composeAdjustmentMessage(int $applied, int $released, int $reserved, int $remainingWaitingDemand, array $results, string $externalSync): string
    {
        $parts = [$applied > 1 ? "Stock updated for {$applied} variants." : 'Stock updated.'];

        if ($released > 0) {
            $parts[] = $released === 1
                ? '1 waiting order moved to Pick & Pack.'
                : "{$released} waiting orders moved to Pick & Pack.";
        } elseif ($remainingWaitingDemand > 0) {
            $parts[] = "No waiting orders released; still missing {$remainingWaitingDemand} unit(s).";
        }

        if ($reserved > 0) {
            $parts[] = $reserved === 1 ? '1 unit reserved for waiting orders.' : "{$reserved} units reserved for waiting orders.";
        }

        if (count($results) === 1) {
            $parts[] = "Available: {$results[0]['available']}.";
        }

        $parts[] = match ($externalSync) {
            'queued' => 'External stock sync queued.',
            'partial' => 'External stock sync partially failed.',
            'failed' => 'External stock sync failed.',
            default => null,
        };

        return implode(' ', array_filter($parts));
    }

    /**
     * Read-only preview of what an adjustment WOULD do — never writes
     * anything. Mirrors the formula InventoryEngine/WaitingStockReallocationService
     * actually apply (reserve FIFO up to available, never touch on_hand
     * beyond the requested change) so the modal can show an honest "expected
     * after save" before the user commits.
     */
    public function previewAdjustment(Request $request, Product $product): JsonResponse
    {
        $store = $request->user()->getActiveStore();
        abort_if($store === null || $product->store_id !== $store->id, 403);

        $validated = $request->validate([
            'warehouse_id' => ['required', 'string'],
            'variant_id'   => ['nullable', 'string'],
            'mode'         => ['required', 'in:set,delta,add,remove'],
            'quantity'     => ['required', 'integer'],
        ]);

        $warehouse = $store->warehouses()->where('warehouses.id', $validated['warehouse_id'])->first();
        abort_if($warehouse === null, 422, 'Warehouse not found for this store.');

        $variant = null;
        if (! empty($validated['variant_id'])) {
            $variant = $product->variants()->whereKey($validated['variant_id'])->firstOrFail();
        }

        // Deliberately NOT CatalogInventoryService::resolve()/forCatalog() —
        // those create an InventoryItem as a side effect when none exists
        // yet. A preview must never mutate anything.
        $link = $variant !== null
            ? VariantInventoryLink::withoutOrganizationTenancy(fn () => VariantInventoryLink::query()
                ->where('product_variant_id', $variant->id)->with('inventoryItem')->first())
            : ProductInventoryLink::withoutOrganizationTenancy(fn () => ProductInventoryLink::query()
                ->where('product_id', $product->id)->with('inventoryItem')->first());
        $item = $link?->inventoryItem;

        $balance = $item !== null
            ? WarehouseInventoryBalance::withoutOrganizationTenancy(fn () => WarehouseInventoryBalance::query()
                ->where('inventory_item_id', $item->id)->where('warehouse_id', $warehouse->id)->first())
            : null;

        $currentOnHand         = (int) ($balance?->on_hand ?? 0);
        $currentReserved       = (int) ($balance?->reserved ?? 0);
        $currentTransferReserved = (int) ($balance?->transfer_reserved ?? 0);
        $currentAvailable      = max(0, $currentOnHand - $currentReserved - $currentTransferReserved);

        $waitingDemand = 0;
        $affectedWaitingOrdersCount = 0;

        if ($item !== null) {
            $openShortages = InventoryReservation::withoutOrganizationTenancy(fn () => InventoryReservation::query()
                ->where('inventory_item_id', $item->id)
                ->where('warehouse_id', $warehouse->id)
                ->where('shortage_quantity', '>', 0)
                ->get(['allocation_id', 'shortage_quantity']));

            $waitingDemand = (int) $openShortages->sum('shortage_quantity');
            $affectedWaitingOrdersCount = $openShortages->pluck('allocation_id')->unique()->count();
        }

        $quantity = (int) $validated['quantity'];
        $expectedOnHand = max(0, match ($validated['mode']) {
            'set'    => $quantity,
            'delta'  => $currentOnHand + $quantity,
            'add'    => $currentOnHand + max(0, $quantity),
            'remove' => $currentOnHand - max(0, $quantity),
        });

        $expectedAvailableBeforeRelease = max(0, $expectedOnHand - $currentReserved - $currentTransferReserved);
        $releasableWaitingUnits = min($waitingDemand, $expectedAvailableBeforeRelease);
        $expectedReserved  = $currentReserved + $releasableWaitingUnits;
        $expectedAvailable = max(0, $expectedOnHand - $expectedReserved - $currentTransferReserved);

        return response()->json([
            'current_on_hand'   => $currentOnHand,
            'current_reserved'  => $currentReserved,
            'current_available' => $currentAvailable,
            'waiting_demand'    => $waitingDemand,
            'releasable_waiting_units' => $releasableWaitingUnits,
            'expected_on_hand'   => $expectedOnHand,
            'expected_reserved'  => $expectedReserved,
            'expected_available' => $expectedAvailable,
            'affected_waiting_orders_count' => $affectedWaitingOrdersCount,
            'inventory_missing' => $item === null,
        ]);
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
     * display names, PLUS the InventoryEngine chain (inventory link ->
     * InventoryItem -> WarehouseInventoryBalance) both models need for the
     * on_hand/reserved/available numbers — one query per relation for the
     * whole page, never per row. Per-warehouse legacy quantities still come
     * from loadBreakdowns() (see its own docblock on why it stays separate).
     *
     * @return array<string, mixed>
     */
    private function variantEagerLoads(): array
    {
        return [
            'variants' => fn ($q) => $q->with('attributeValues.attribute', 'inventoryLink.inventoryItem.balances'),
            'inventoryLink.inventoryItem.balances',
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
     * @param  array<string, int>  $waitingByItemId
     * @return array<string, mixed>
     */
    private function presentProduct(Product $product, array $breakdown, array $waitingByItemId = []): array
    {
        $hasVariants = $product->isVariable() && $product->variants->isNotEmpty();

        $simpleWh  = $breakdown['simpleWh']  ?? [];
        $variantWh = $breakdown['variantWh'] ?? [];
        $byVariant = $breakdown['byVariant'] ?? [];

        $productWh = $hasVariants ? $variantWh : $simpleWh;

        $variants = $hasVariants
            ? $product->variants->map(function (ProductVariant $v) use ($byVariant, $waitingByItemId) {
                $vwh = $byVariant[$v->id] ?? [];
                $engine = $this->snapshots->forVariantAcrossWarehouses($v, $this->warehouseScope, $waitingByItemId);

                return [
                    'id'        => $v->id,
                    'name'      => $v->getDisplayName(),
                    'sku'       => $v->sku,
                    'stock'     => $this->effectiveTotal($vwh),
                    'breakdown' => $this->breakdownList($vwh),
                    // Engine-sourced (WarehouseInventoryBalance) — the
                    // authoritative on_hand/reserved/available numbers.
                    // `stock` above stays for backward compat but is the
                    // legacy sellable-projection figure; the UI shows these.
                    'on_hand'           => $engine['on_hand'],
                    'reserved'          => $engine['reserved'],
                    'available'         => $engine['available'],
                    'waiting_demand'    => $engine['waiting_demand'],
                    'inventory_item_id' => $engine['inventory_item_id'],
                    'inventory_missing' => $engine['inventory_missing'],
                ];
            })->values()->all()
            : [];

        $productEngine = $hasVariants
            ? $this->sumEngineAcrossVariants($variants)
            : $this->snapshots->forProductAcrossWarehouses($product, $this->warehouseScope, $waitingByItemId);

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
            // Engine-sourced product-level summary — sum of its variants'
            // engine numbers for a variable product, its own for a simple one.
            'on_hand'           => $productEngine['on_hand'],
            'reserved'          => $productEngine['reserved'],
            'available'         => $productEngine['available'],
            'waiting_demand'    => $productEngine['waiting_demand'],
            'inventory_item_id' => $productEngine['inventory_item_id'] ?? null,
            'inventory_missing' => $productEngine['inventory_missing'] ?? true,
        ];
    }

    /** @param array<int, array<string, mixed>> $variants */
    private function sumEngineAcrossVariants(array $variants): array
    {
        return [
            'on_hand'        => array_sum(array_column($variants, 'on_hand')),
            'reserved'       => array_sum(array_column($variants, 'reserved')),
            'available'      => array_sum(array_column($variants, 'available')),
            'waiting_demand' => array_sum(array_column($variants, 'waiting_demand')),
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
