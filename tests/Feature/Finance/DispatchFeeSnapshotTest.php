<?php

declare(strict_types=1);

use App\Enums\FulfillmentStatus;
use App\Models\City;
use App\Models\CityDeliveryProviderMapping;
use App\Models\DeliveryConnection;
use App\Models\DeliveryProviderCity;
use App\Models\FinanceTransaction;
use App\Models\Order;
use App\Models\Shipment;
use App\Models\Store;
use App\Models\User;
use App\Services\Delivery\OzonShipmentService;
use App\Services\Finance\FinanceAccountService;
use App\Services\Finance\FinanceDeliveryProviderFeeCalculator;
use App\Services\OrganizationProvisioner;
use Illuminate\Support\Facades\Http;

/*
|--------------------------------------------------------------------------
| Fee snapshot is frozen at dispatch / hand-off, not only at delivery.
|--------------------------------------------------------------------------
*/

function dfsSendOzonShipment(float $tariff = 22.0): array
{
    $owner = User::factory()->create(['role' => 'store_admin', 'onboarding_completed_at' => now()]);
    $organization = app(OrganizationProvisioner::class)->createOwnedOrganization($owner, 'Dispatch Fee Store');
    $store = Store::factory()->create(['user_id' => $owner->id, 'organization_id' => $organization->id, 'name' => 'Dispatch Fee Store']);
    $store->ensureDefaultRoles();
    app(FinanceAccountService::class)->ensureSeeded($organization);

    $connection = DeliveryConnection::create([
        'store_id' => $store->id, 'organization_id' => $organization->id,
        'provider_code' => 'ozon', 'name' => 'Ozon Express',
        'credentials' => ['customer_id' => 'CUST123', 'api_key' => 'secret'],
        'settings' => [], 'status' => DeliveryConnection::STATUS_CONNECTED,
    ]);

    $city = City::create(['country_code' => 'MA', 'code' => 'CAS', 'name' => 'Casablanca', 'is_active' => true]);
    $providerCity = DeliveryProviderCity::create([
        'store_id' => $store->id, 'provider_code' => 'ozon', 'provider_city_id' => '17',
        'city_name' => 'Casablanca', 'delivered_price' => $tariff, 'returned_price' => 6, 'refused_price' => 6,
    ]);
    CityDeliveryProviderMapping::create([
        'store_id' => $store->id, 'city_id' => $city->id, 'provider_code' => 'ozon',
        'delivery_provider_city_id' => $providerCity->id,
    ]);

    // Set the status directly rather than running OrderWorkflowService
    // transitions: on an organization-backed store the Confirm step forces
    // warehouse allocation, which needs inventory-linked line items this
    // fee-focused fixture deliberately does not build. OzonShipmentService
    // ::send() only reads fulfillment_status; it never re-runs allocation.
    $order = Order::factory()->create([
        'store_id' => $store->id, 'organization_id' => $organization->id,
        'fulfillment_status' => FulfillmentStatus::ReadyForDelivery,
        'customer_name' => 'Sara', 'customer_phone' => '0611223344',
        'confirmed_shipping_address' => '12 Rue Ozon', 'shipping_city_id' => $city->id, 'total' => 400,
        'items' => [['name' => 'Item', 'quantity' => 1, 'unit_price' => 400, 'line_total' => 400]],
        'platform_data' => [],
    ]);

    Http::fake([
        'api.ozonexpress.ma/*/add-parcel' => Http::response(['ADD-PARCEL' => ['RESULT' => 'SUCCESS', 'NEW-PARCEL' => ['TRACKING-NUMBER' => 'OZE-FEE-1']]], 200),
        'api.ozonexpress.ma/*/parcel-info' => Http::response(['PARCEL-INFO' => ['RESULT' => 'SUCCESS']], 200),
        'api.ozonexpress.ma/*/tracking' => Http::response(['TRACKING' => ['RESULT' => 'SUCCESS', 'status' => 'Nouveau colis']], 200),
    ]);

    $shipment = app(OzonShipmentService::class)->send($order, $connection, [], $owner);

    return [$owner, $store, $organization, $providerCity, $shipment];
}

it('freezes a carrier fee snapshot on the shipment at dispatch, before delivery', function (): void {
    [, , , , $shipment] = dfsSendOzonShipment(tariff: 22.0);

    $shipment->refresh();

    expect($shipment->status)->toBe(Shipment::STATUS_SENT_TO_CARRIER)
        ->and($shipment->fee_calculated_at)->not->toBeNull()
        ->and((float) $shipment->expected_delivery_fee)->toBe(22.0)
        ->and($shipment->fee_source)->toBe('api_quote')
        ->and((float) $shipment->effectiveCarrierFee())->toBeGreaterThanOrEqual(22.0);
});

it('never changes an old shipment fee after the provider city tariff is updated later', function (): void {
    [, , , $providerCity, $shipment] = dfsSendOzonShipment(tariff: 22.0);

    expect((float) $shipment->fresh()->expected_delivery_fee)->toBe(22.0);

    // The provider raises its Casablanca tariff next month...
    $providerCity->update(['delivered_price' => 99]);

    // ...re-running the (idempotent) snapshot never touches the frozen fee.
    app(FinanceDeliveryProviderFeeCalculator::class)->snapshotForShipment($shipment->fresh());

    expect((float) $shipment->fresh()->expected_delivery_fee)->toBe(22.0);
});

it('is idempotent — a second snapshot call after dispatch is a no-op', function (): void {
    [, , , , $shipment] = dfsSendOzonShipment(tariff: 30.0);

    $firstCalculatedAt = $shipment->fresh()->fee_calculated_at;

    app(FinanceDeliveryProviderFeeCalculator::class)->snapshotForShipment($shipment->fresh());
    app(FinanceDeliveryProviderFeeCalculator::class)->snapshotForShipment($shipment->fresh());

    expect($shipment->fresh()->fee_calculated_at->equalTo($firstCalculatedAt))->toBeTrue()
        ->and((float) $shipment->fresh()->expected_delivery_fee)->toBe(30.0);
});

it('does not create any finance transaction as a side effect of the fee snapshot', function (): void {
    [, , $organization, , $shipment] = dfsSendOzonShipment(tariff: 22.0);

    $before = FinanceTransaction::query()->count();

    app(FinanceDeliveryProviderFeeCalculator::class)->snapshotForShipment($shipment->fresh());

    expect(FinanceTransaction::query()->count())->toBe($before);
});
