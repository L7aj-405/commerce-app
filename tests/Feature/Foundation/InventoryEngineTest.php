<?php

declare(strict_types=1);

use App\Enums\FulfillmentStatus;
use App\Enums\OrderStatus;
use App\Jobs\ExternalStockPushJob;
use App\Models\City;
use App\Models\InventoryAllocation;
use App\Models\InventoryItem;
use App\Models\InventoryLedgerEntry;
use App\Models\InventoryTransfer;
use App\Models\Order;
use App\Models\Organization;
use App\Models\Product;
use App\Models\Stock;
use App\Models\Store;
use App\Models\User;
use App\Models\Warehouse;
use App\Models\WarehouseInventoryBalance;
use App\Services\Agency\AgencyWorkspaceService;
use App\Services\Inventory\CatalogInventoryService;
use App\Services\Inventory\InventoryEngine;
use App\Services\Inventory\InventoryTransferService;
use App\Services\Inventory\WarehouseAllocationService;
use App\Services\Orders\OrderWorkflowService;
use App\Services\OrganizationProvisioner;
use Illuminate\Support\Facades\Queue;
use Illuminate\Validation\ValidationException;

function inventoryMerchant(string $name = 'Merchant'): array
{
    $user = User::factory()->create(['onboarding_completed_at' => now()]);
    $org = app(OrganizationProvisioner::class)->createOwnedOrganization($user, $name, Organization::TYPE_MERCHANT);
    $store = Store::create([
        'organization_id' => $org->id,
        'user_id' => $user->id,
        'name' => $name . ' Brand',
        'type' => 'online',
        'status' => 'active',
        'country' => 'MA',
        'currency' => 'MAD',
    ]);
    $warehouse = Warehouse::withoutTenancy(fn () => Warehouse::create([
        'user_id' => $user->id,
        'owner_organization_id' => $org->id,
        'operator_organization_id' => $org->id,
        'name' => $name . ' Warehouse',
        'type' => Warehouse::TYPE_STANDARD,
        'country' => 'MA',
        'is_active' => true,
        'is_default' => true,
    ]));
    $warehouse->accessibleOrganizations()->sync([$org->id => ['is_active' => true]]);
    $store->warehouses()->sync([$warehouse->id => ['is_primary' => true, 'priority' => 1]]);

    return compact('user', 'org', 'store', 'warehouse');
}

function inventoryProduct(Store $store, string $sku, string $name='Inventory product'): Product
{
    return Product::withoutTenancy(fn () => Product::create([
        'store_id' => $store->id,
        'name' => $name,
        'sku' => $sku,
        'type' => 'simple',
        'status' => 'active',
        'price' => 100,
    ]));
}

beforeEach(function (): void {
    Queue::fake();
});

it('shares one physical inventory item across brands in the same organization by master SKU', function (): void {
    ['user'=>$user,'org'=>$org,'store'=>$storeA] = inventoryMerchant('Fashion');
    $storeB = Store::create([
        'organization_id'=>$org->id,'user_id'=>$user->id,'name'=>'Second Brand','type'=>'online','status'=>'active','country'=>'MA','currency'=>'MAD',
    ]);

    $a = inventoryProduct($storeA, 'MASTER-001', 'Brand A product');
    $b = inventoryProduct($storeB, 'MASTER-001', 'Brand B product');

    $catalog = app(CatalogInventoryService::class);
    $itemA = $catalog->forCatalog($a);
    $itemB = $catalog->forCatalog($b);

    expect($itemA?->id)->toBe($itemB?->id)
        ->and($itemA?->organization_id)->toBe($org->id)
        ->and($itemA?->productLinks()->count())->toBe(2);
});

it('keeps client stock ownership isolated inside one agency warehouse', function (): void {
    $user = User::factory()->create(['onboarding_completed_at'=>now()]);
    $agency = app(OrganizationProvisioner::class)->createOwnedOrganization($user,'3PL',Organization::TYPE_AGENCY);
    $workspace = app(AgencyWorkspaceService::class);
    $clientA = $workspace->createClient($agency,$user,['client_name'=>'Client A','brand_name'=>'A','country'=>'MA','currency'=>'MAD']);
    $clientB = $workspace->createClient($agency,$user,['client_name'=>'Client B','brand_name'=>'B','country'=>'MA','currency'=>'MAD']);
    $warehouse = $workspace->createAgencyWarehouse($agency,$user,['name'=>'Casa Hub','city'=>'Casablanca','country'=>'MA']);
    $workspace->assignWarehouse($agency,$clientA,$warehouse,$user);
    $workspace->assignWarehouse($agency,$clientB,$warehouse,$user);

    $productA = inventoryProduct($clientA->stores->first(),'SAME-SKU','A item');
    $productB = inventoryProduct($clientB->stores->first(),'SAME-SKU','B item');
    $catalog=app(CatalogInventoryService::class); $engine=app(InventoryEngine::class);
    $itemA=$catalog->forCatalog($productA); $itemB=$catalog->forCatalog($productB);
    $engine->setOnHand($itemA,$warehouse,100,'initial_import',null,$user);
    $engine->setOnHand($itemB,$warehouse,40,'initial_import',null,$user);

    expect($itemA->id)->not->toBe($itemB->id)
        ->and(WarehouseInventoryBalance::withoutOrganizationTenancy(fn()=>WarehouseInventoryBalance::query()->where('inventory_item_id',$itemA->id)->value('on_hand')))->toBe(100)
        ->and(WarehouseInventoryBalance::withoutOrganizationTenancy(fn()=>WarehouseInventoryBalance::query()->where('inventory_item_id',$itemB->id)->value('on_hand')))->toBe(40);
});

it('reserves stock atomically and prevents a second order from overselling the last unit', function (): void {
    ['user'=>$user,'store'=>$store,'warehouse'=>$warehouse] = inventoryMerchant();
    $product=inventoryProduct($store,'LAST-ONE');
    $item=app(CatalogInventoryService::class)->forCatalog($product);
    $engine=app(InventoryEngine::class);
    $engine->setOnHand($item,$warehouse,1,'initial_import',null,$user);
    $engine->reserve($item,$warehouse,1,null,$user);

    expect($engine->balance($item,$warehouse)->available())->toBe(0)
        ->and(fn()=> $engine->reserve($item,$warehouse,1,null,$user))->toThrow(ValidationException::class);
});

it('allocates a confirmed Marrakech order to the warehouse configured to serve Marrakech', function (): void {
    ['user'=>$user,'org'=>$org,'store'=>$store,'warehouse'=>$casa] = inventoryMerchant();
    $casa->update(['name'=>'Casa Hub','city'=>'Casablanca']);
    $marrakech=Warehouse::withoutTenancy(fn()=>Warehouse::create([
        'user_id'=>$user->id,'owner_organization_id'=>$org->id,'operator_organization_id'=>$org->id,'name'=>'Marrakech Hub','city'=>'Marrakech','country'=>'MA','type'=>Warehouse::TYPE_STANDARD,'is_active'=>true,
    ]));
    $marrakech->accessibleOrganizations()->sync([$org->id=>['is_active'=>true]]);
    $store->warehouses()->syncWithoutDetaching([$marrakech->id=>['is_primary'=>false,'priority'=>2]]);
    $city=City::query()->where('code','MA-MARRAKECH')->firstOrFail();
    $marrakech->serviceCities()->sync([$city->id=>['priority'=>1,'is_active'=>true]]);

    $product=inventoryProduct($store,'MARR-001'); $item=app(CatalogInventoryService::class)->forCatalog($product);
    app(InventoryEngine::class)->setOnHand($item,$casa,20,'initial_import',null,$user);
    app(InventoryEngine::class)->setOnHand($item,$marrakech,5,'initial_import',null,$user);
    $order=Order::factory()->create(['store_id'=>$store->id,'shipping_city_id'=>$city->id,'items'=>[['product_id'=>$product->id,'name'=>$product->name,'sku'=>$product->sku,'quantity'=>2,'price'=>100]]]);

    $allocation=app(WarehouseAllocationService::class)->allocate($order,$city,$user);

    expect($allocation->warehouse_id)->toBe($marrakech->id)
        ->and($allocation->status)->toBe(InventoryAllocation::STATUS_RESERVED)
        ->and(app(InventoryEngine::class)->balance($item,$marrakech)->reserved)->toBe(2)
        ->and(app(InventoryEngine::class)->balance($item,$casa)->reserved)->toBe(0);
});

it('creates an internal transfer when the local warehouse has most of an order but is missing stock', function (): void {
    ['user'=>$user,'org'=>$org,'store'=>$store,'warehouse'=>$casa] = inventoryMerchant();
    $casa->update(['name'=>'Casa Hub','city'=>'Casablanca']);
    $marrakech=Warehouse::withoutTenancy(fn()=>Warehouse::create([
        'user_id'=>$user->id,'owner_organization_id'=>$org->id,'operator_organization_id'=>$org->id,'name'=>'Marrakech Hub','city'=>'Marrakech','country'=>'MA','type'=>Warehouse::TYPE_STANDARD,'is_active'=>true,
    ]));
    $marrakech->accessibleOrganizations()->sync([$org->id=>['is_active'=>true]]);
    $store->warehouses()->syncWithoutDetaching([$marrakech->id=>['is_primary'=>false,'priority'=>2]]);
    $city=City::query()->where('code','MA-MARRAKECH')->firstOrFail();
    $marrakech->serviceCities()->sync([$city->id=>['priority'=>1,'is_active'=>true]]);

    $product=inventoryProduct($store,'TRANSFER-001'); $item=app(CatalogInventoryService::class)->forCatalog($product); $engine=app(InventoryEngine::class);
    $engine->setOnHand($item,$marrakech,3,'initial_import',null,$user);
    $engine->setOnHand($item,$casa,10,'initial_import',null,$user);
    $order=Order::factory()->create(['store_id'=>$store->id,'shipping_city_id'=>$city->id,'items'=>[['product_id'=>$product->id,'name'=>$product->name,'sku'=>$product->sku,'quantity'=>4,'price'=>100]]]);

    $allocation=app(WarehouseAllocationService::class)->allocate($order,$city,$user);
    $transfer=InventoryTransfer::withoutOrganizationTenancy(fn()=>InventoryTransfer::query()->firstOrFail());

    expect($allocation->warehouse_id)->toBe($marrakech->id)
        ->and($allocation->status)->toBe(InventoryAllocation::STATUS_WAITING_TRANSFER)
        ->and($transfer->source_warehouse_id)->toBe($casa->id)
        ->and($transfer->destination_warehouse_id)->toBe($marrakech->id)
        ->and($engine->balance($item,$marrakech)->reserved)->toBe(3)
        ->and($engine->balance($item,$casa)->transfer_reserved)->toBe(1);

    $moves=app(InventoryTransferService::class);
    $moves->ship($transfer,$user);
    $moves->receive($transfer->refresh(),$user);

    expect($allocation->fresh()->status)->toBe(InventoryAllocation::STATUS_RESERVED)
        ->and($engine->balance($item,$marrakech)->reserved)->toBe(4)
        ->and($engine->balance($item,$casa)->on_hand)->toBe(9);
});

it('keeps legacy stock writes reservation-safe while old modules are being migrated', function (): void {
    ['user'=>$user,'store'=>$store,'warehouse'=>$warehouse] = inventoryMerchant();
    $product=inventoryProduct($store,'BRIDGE-001'); $item=app(CatalogInventoryService::class)->forCatalog($product); $engine=app(InventoryEngine::class);
    $engine->setOnHand($item,$warehouse,10,'initial_import',null,$user);
    $engine->reserve($item,$warehouse,2,null,$user);

    $legacy=Stock::withoutTenancy(fn()=>Stock::query()->where('product_id',$product->id)->where('warehouse_id',$warehouse->id)->firstOrFail());
    expect($legacy->quantity)->toBe(8);
    $legacy->update(['quantity'=>7]); // e.g. a legacy POS sale of one available unit

    $balance=$engine->balance($item,$warehouse)->refresh();
    expect($balance->on_hand)->toBe(9)->and($balance->reserved)->toBe(2)->and($balance->available())->toBe(7);
});

it('uses reserve then consume semantics in the online order workflow', function (): void {
    ['user'=>$user,'store'=>$store,'warehouse'=>$warehouse] = inventoryMerchant();
    $product=inventoryProduct($store,'FLOW-001'); $item=app(CatalogInventoryService::class)->forCatalog($product); $engine=app(InventoryEngine::class);
    $engine->setOnHand($item,$warehouse,5,'initial_import',null,$user);
    $order=Order::factory()->create([
        'store_id'=>$store->id,'status'=>OrderStatus::Pending,'fulfillment_status'=>FulfillmentStatus::Pending,
        'items'=>[['product_id'=>$product->id,'name'=>$product->name,'sku'=>$product->sku,'quantity'=>1,'price'=>100]],
    ]);
    $workflow=app(OrderWorkflowService::class);
    $order = $workflow->transition($order,FulfillmentStatus::Confirmed,$user);
    expect($order->fulfillment_status)->toBe(FulfillmentStatus::ReadyForPicking)
        ->and($engine->balance($item,$warehouse)->on_hand)->toBe(5)
        ->and($engine->balance($item,$warehouse)->reserved)->toBe(1)
        ->and($engine->balance($item,$warehouse)->available())->toBe(4);
    $workflow->transition($order->refresh(),FulfillmentStatus::Picking,$user);
    $workflow->transition($order->refresh(),FulfillmentStatus::Packing,$user);
    $workflow->transition($order->refresh(),FulfillmentStatus::ReadyForDelivery,$user);
    expect($engine->balance($item,$warehouse)->on_hand)->toBe(4)->and($engine->balance($item,$warehouse)->reserved)->toBe(0)->and($engine->balance($item,$warehouse)->available())->toBe(4)
        ->and(InventoryLedgerEntry::withoutOrganizationTenancy(fn()=>InventoryLedgerEntry::query()->where('inventory_item_id',$item->id)->count()))->toBeGreaterThanOrEqual(3);
    Queue::assertPushed(ExternalStockPushJob::class);
});

it('releases both local and transfer reservations when a waiting order is cancelled', function (): void {
    ['user'=>$user,'org'=>$org,'store'=>$store,'warehouse'=>$casa] = inventoryMerchant('Cancel transfer');
    $casa->update(['name'=>'Casa Hub','city'=>'Casablanca']);

    $marrakech=Warehouse::withoutTenancy(fn()=>Warehouse::create([
        'user_id'=>$user->id,
        'owner_organization_id'=>$org->id,
        'operator_organization_id'=>$org->id,
        'name'=>'Marrakech Hub',
        'city'=>'Marrakech',
        'country'=>'MA',
        'type'=>Warehouse::TYPE_STANDARD,
        'is_active'=>true,
    ]));
    $marrakech->accessibleOrganizations()->sync([$org->id=>['is_active'=>true]]);
    $store->warehouses()->syncWithoutDetaching([$marrakech->id=>['is_primary'=>false,'priority'=>2]]);

    $city=City::query()->where('code','MA-MARRAKECH')->firstOrFail();
    $marrakech->serviceCities()->sync([$city->id=>['priority'=>1,'is_active'=>true]]);

    $product=inventoryProduct($store,'CANCEL-TRF-001');
    $item=app(CatalogInventoryService::class)->forCatalog($product);
    $engine=app(InventoryEngine::class);
    $engine->setOnHand($item,$marrakech,3,'initial_import',null,$user);
    $engine->setOnHand($item,$casa,10,'initial_import',null,$user);

    $order=Order::factory()->create([
        'store_id'=>$store->id,
        'status'=>OrderStatus::Pending,
        'fulfillment_status'=>FulfillmentStatus::Pending,
        'shipping_city_id'=>$city->id,
        'items'=>[['product_id'=>$product->id,'name'=>$product->name,'sku'=>$product->sku,'quantity'=>4,'price'=>100]],
    ]);

    $workflow=app(OrderWorkflowService::class);
    $order=$workflow->transition($order,FulfillmentStatus::Confirmed,$user);
    $transfer=InventoryTransfer::withoutOrganizationTenancy(fn()=>InventoryTransfer::query()->firstOrFail());

    expect($order->fulfillment_status)->toBe(FulfillmentStatus::WaitingForStock)
        ->and($engine->balance($item,$marrakech)->reserved)->toBe(3)
        ->and($engine->balance($item,$casa)->transfer_reserved)->toBe(1);

    $workflow->transition($order,FulfillmentStatus::Cancelled,$user,'customer cancelled');

    expect($engine->balance($item,$marrakech)->reserved)->toBe(0)
        ->and($engine->balance($item,$casa)->transfer_reserved)->toBe(0)
        ->and($transfer->fresh()->status)->toBe(InventoryTransfer::CANCELLED)
        ->and($order->fresh()->inventoryAllocation?->status)->toBe(InventoryAllocation::STATUS_RELEASED);
});

it('projects existing shared inventory immediately when a second brand links the same master SKU', function (): void {
    ['user'=>$user,'org'=>$org,'store'=>$storeA,'warehouse'=>$warehouse] = inventoryMerchant('Shared projection');
    $storeB = Store::create([
        'organization_id'=>$org->id,
        'user_id'=>$user->id,
        'name'=>'Brand B',
        'type'=>'online',
        'status'=>'active',
        'country'=>'MA',
        'currency'=>'MAD',
    ]);

    $productA=inventoryProduct($storeA,'SHARED-100','Brand A');
    $catalog=app(CatalogInventoryService::class);
    $item=$catalog->forCatalog($productA);
    app(InventoryEngine::class)->setOnHand($item,$warehouse,12,'initial_import',null,$user);

    $productB=inventoryProduct($storeB,'SHARED-100','Brand B');
    $linked=$catalog->forCatalog($productB);
    $projected=Stock::withoutTenancy(fn()=>Stock::query()
        ->where('product_id',$productB->id)
        ->where('warehouse_id',$warehouse->id)
        ->value('quantity'));

    expect($linked?->id)->toBe($item->id)
        ->and((int)$projected)->toBe(12);
});
