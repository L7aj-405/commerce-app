<?php

declare(strict_types=1);

use App\Models\Product;
use App\Models\Stock;
use App\Models\StockLedger;
use App\Models\StockTransfer;
use App\Models\Store;
use App\Models\User;
use App\Models\Warehouse;
use App\Services\Pos\DocumentGenerationService;
use App\Services\Stocks\StockTransferService;
use Illuminate\Validation\ValidationException;

/*
|--------------------------------------------------------------------------
| Stock Transfer & Outbound Movement (Bon de Sortie)
|--------------------------------------------------------------------------
*/

beforeEach(function () {
    $this->actor = User::factory()->create();
    $this->store = Store::factory()->create(['user_id' => $this->actor->id]);

    $this->source = $this->store->getPrimaryWarehouse();

    $this->dest = Warehouse::create([
        'user_id'   => $this->actor->id,
        'name'      => 'Overflow Depot',
        'type'      => Warehouse::TYPE_STANDARD,
        'is_active' => true,
    ]);
    $this->store->warehouses()->attach($this->dest->id, ['is_primary' => false, 'priority' => 2]);

    $this->product = Product::create([
        'store_id' => $this->store->id,
        'name'     => 'Blue Hoodie',
        'sku'      => 'BH-L-01',
        'type'     => 'simple',
        'status'   => 'active',
        'price'    => 100,
    ]);

    Stock::create([
        'product_id'   => $this->product->id,
        'warehouse_id' => $this->source->id,
        'quantity'     => 10,
    ]);

    $this->service = app(StockTransferService::class);
});

function transferData(array $overrides = []): array
{
    return array_merge([
        'source_warehouse_id'      => test()->source->id,
        'destination_kind'         => 'warehouse',
        'destination_warehouse_id' => test()->dest->id,
        'transfer_date'            => now()->toDateString(),
        'notes'                    => 'Restocking depot',
        'items'                    => [
            ['product_id' => test()->product->id, 'variant_id' => null, 'quantity' => 4],
        ],
    ], $overrides);
}

it('moves stock between warehouses and records a double-entry ledger', function () {
    $transfer = $this->service->create($this->store, transferData(), $this->actor);

    // Source down, destination up.
    expect((int) Stock::where('product_id', $this->product->id)->where('warehouse_id', $this->source->id)->value('quantity'))->toBe(6);
    expect((int) Stock::where('product_id', $this->product->id)->where('warehouse_id', $this->dest->id)->value('quantity'))->toBe(4);

    // Transfer + item recorded.
    expect($transfer->reference)->toStartWith('BS-');
    expect($transfer->total_quantity)->toBe(4);
    expect($transfer->items)->toHaveCount(1);
    expect($transfer->items->first()->product_name)->toBe('Blue Hoodie');

    // Two transfer ledger legs (out + in) linked to the transfer.
    $legs = StockLedger::where('type', 'transfer')->where('source_id', $transfer->id)->get();
    expect($legs)->toHaveCount(2);
    expect($legs->pluck('quantity_change')->sort()->values()->all())->toBe([-4, 4]);
    expect($legs->every(fn ($l) => $l->reference_number === $transfer->reference))->toBeTrue();
});

it('rolls the whole transfer back when a line exceeds available stock', function () {
    $call = fn () => $this->service->create($this->store, transferData([
        'items' => [['product_id' => $this->product->id, 'variant_id' => null, 'quantity' => 999]],
    ]), $this->actor);

    expect($call)->toThrow(ValidationException::class);

    // Nothing moved, nothing persisted.
    expect((int) Stock::where('warehouse_id', $this->source->id)->value('quantity'))->toBe(10);
    expect(StockTransfer::count())->toBe(0);
    expect(StockLedger::where('type', 'transfer')->count())->toBe(0);
});

it('lets stock leave to a team destination without a warehouse credit', function () {
    $transfer = $this->service->create($this->store, transferData([
        'destination_kind'         => 'team',
        'destination_warehouse_id' => null,
        'destination_label'        => 'Sales team — North',
        'items'                    => [['product_id' => $this->product->id, 'variant_id' => null, 'quantity' => 3]],
    ]), $this->actor);

    expect((int) Stock::where('warehouse_id', $this->source->id)->value('quantity'))->toBe(7);
    // Only the outbound leg exists — the goods left the tracked warehouses.
    expect(StockLedger::where('type', 'transfer')->where('source_id', $transfer->id)->count())->toBe(1);
    expect($transfer->destination_kind)->toBe('team');
    expect($transfer->destinationName())->toBe('Sales team — North');
});

it('links a dashboard-created warehouse into the store so it appears in transfers', function () {
    // A warehouse created the way WarehouseController does it: owned by the user,
    // but NOT attached to the store pivot the transfer pickers read from.
    $orphan = Warehouse::create([
        'user_id'   => $this->actor->id,
        'name'      => 'Secondary Depot',
        'type'      => Warehouse::TYPE_STANDARD,
        'is_active' => true,
    ]);

    expect($this->store->warehouses()->pluck('warehouses.id'))->not->toContain($orphan->id);

    $this->store->attachOwnerWarehouses();

    $ids = $this->store->warehouses()->pluck('warehouses.id');
    expect($ids)->toContain($orphan->id);
    // Idempotent — a second heal doesn't duplicate the pivot row.
    $this->store->attachOwnerWarehouses();
    expect($this->store->warehouses()->where('warehouses.id', $orphan->id)->count())->toBe(1);
});

it('does not steal a warehouse already attached to another store', function () {
    $otherStore = Store::factory()->create(['user_id' => $this->actor->id]);
    $claimed = Warehouse::create([
        'user_id'   => $this->actor->id,
        'name'      => 'Other Store Depot',
        'type'      => Warehouse::TYPE_STANDARD,
        'is_active' => true,
    ]);
    $otherStore->warehouses()->attach($claimed->id, ['is_primary' => false, 'priority' => 5]);

    $this->store->attachOwnerWarehouses();

    expect($this->store->warehouses()->pluck('warehouses.id'))->not->toContain($claimed->id);
});

it('marks a warehouse as the store primary', function () {
    $this->store->markPrimaryWarehouse($this->dest);

    expect($this->store->getPrimaryWarehouse()->id)->toBe($this->dest->id);
});

it('renders a Bon de Sortie PDF', function () {
    $transfer = $this->service->create($this->store, transferData(), $this->actor);
    $transfer->load(['items', 'store', 'sourceWarehouse', 'destinationWarehouse', 'responsibleMember', 'createdBy']);

    $bytes = app(DocumentGenerationService::class)->renderBonDeSortie([
        'store'            => $transfer->store,
        'currency'         => 'MAD',
        'reference'        => $transfer->reference,
        'status'           => $transfer->status,
        'transfer_date'    => $transfer->transfer_date,
        'generated_at'     => now(),
        'source'           => $transfer->sourceWarehouse->name,
        'destination'      => $transfer->destinationName(),
        'destination_kind' => $transfer->destination_kind,
        'responsible'      => null,
        'created_by'       => $this->actor->name,
        'items'            => [[
            'index' => 1, 'sku' => 'BH-L-01', 'product' => 'Blue Hoodie', 'variant' => null,
            'quantity' => 4, 'unit_price' => 100.0, 'line_value' => 400.0,
        ]],
        'total_quantity'   => 4,
        'total_value'      => 400.0,
        'notes'            => 'Restocking depot',
    ]);

    expect(str_starts_with($bytes, '%PDF'))->toBeTrue();
});
