<?php

declare(strict_types=1);

use App\Enums\FulfillmentStatus;
use App\Models\City;
use App\Models\DeliveryProvider;
use App\Models\DeliveryProviderCity;
use App\Models\DeliveryProviderCityFee;
use App\Models\DeliveryProviderFinanceSetting;
use App\Models\FinanceAccount;
use App\Models\Order;
use App\Models\Organization;
use App\Models\Shipment;
use App\Models\Store;
use App\Models\StoreMember;
use App\Models\StoreRole;
use App\Models\User;
use App\Services\Delivery\ShipmentTrackingService;
use App\Services\Finance\FinanceAccountService;
use App\Services\Finance\FinanceDeliveryProviderFeeCalculator;
use App\Services\OrganizationProvisioner;

/**
 * @return array{0: User, 1: Store, 2: Organization, 3: DeliveryProvider}
 */
function dpfWorkspace(string $name = 'DPF Store'): array
{
    $owner = User::factory()->create(['role' => 'store_admin', 'onboarding_completed_at' => now()]);
    $organization = app(OrganizationProvisioner::class)->createOwnedOrganization($owner, $name);
    $store = Store::factory()->create(['user_id' => $owner->id, 'organization_id' => $organization->id, 'name' => $name]);
    $store->ensureDefaultRoles();
    app(FinanceAccountService::class)->ensureSeeded($organization);
    $provider = DeliveryProvider::where('code', 'ozon')->firstOrFail();

    return [$owner, $store, $organization, $provider];
}

/** A delivered order with an external-carrier shipment routed to $cityName. */
function dpfDeliveredShipment(Store $store, Organization $organization, string $cityName, float $total = 500.0): Shipment
{
    $order = Order::factory()->create([
        'store_id' => $store->id, 'organization_id' => $organization->id, 'total' => $total, 'platform_data' => [],
        'fulfillment_status' => FulfillmentStatus::Delivered,
    ]);

    return Shipment::create([
        'store_id' => $store->id, 'organization_id' => $organization->id,
        'shippable_type' => Order::class, 'shippable_id' => $order->id,
        'provider_code' => 'ozon', 'status' => Shipment::STATUS_DELIVERED,
        'city_name' => $cityName, 'receiver_name' => 'Test Receiver', 'phone' => '0600000000', 'address' => $cityName,
        'delivered_at' => now(),
    ]);
}

function dpfStaffWithPermissions(Store $store, Organization $organization, array $permissions): User
{
    $role = StoreRole::create(['store_id' => $store->id, 'name' => 'DPF Role '.implode('-', $permissions), 'permissions' => $permissions, 'is_system' => false]);
    $staff = User::factory()->create(['role' => 'manager', 'onboarding_completed_at' => now()]);
    app(OrganizationProvisioner::class)->ensureMember($organization, $staff);
    StoreMember::create(['store_id' => $store->id, 'user_id' => $staff->id, 'role' => 'manager', 'store_role_id' => $role->id, 'is_active' => true, 'joined_at' => now()]);

    return $staff;
}

it('lets an authorized user update a provider\'s finance settings', function (): void {
    [$owner, , $organization, $provider] = dpfWorkspace();

    $this->actingAs($owner)->patch("/dashboard/finance/delivery-providers/{$provider->id}", [
        'default_delivery_fee' => 35, 'payout_frequency' => 'biweekly', 'payout_delay_days' => 2,
        'is_cod_enabled' => true, 'is_active' => true,
    ])->assertRedirect();

    $settings = DeliveryProviderFinanceSetting::where('organization_id', $organization->id)->where('delivery_provider_id', $provider->id)->firstOrFail();

    expect((float) $settings->default_delivery_fee)->toBe(35.0)
        ->and($settings->payout_frequency->value)->toBe('biweekly')
        ->and($settings->payout_delay_days)->toBe(2);
});

it('denies a staff member without finance.manage_cod_settlements from updating provider settings', function (): void {
    [, $store, $organization, $provider] = dpfWorkspace();
    $staff = dpfStaffWithPermissions($store, $organization, ['finance.view']);

    $this->actingAs($staff)->patch("/dashboard/finance/delivery-providers/{$provider->id}", [
        'default_delivery_fee' => 35, 'payout_frequency' => 'weekly',
    ])->assertForbidden();

    expect(DeliveryProviderFinanceSetting::where('organization_id', $organization->id)->exists())->toBeFalse();
});

it('uses the city fee override over the provider default fee', function (): void {
    [, $store, $organization, $provider] = dpfWorkspace();
    DeliveryProviderFinanceSetting::create(['organization_id' => $organization->id, 'delivery_provider_id' => $provider->id, 'default_delivery_fee' => 30, 'payout_frequency' => 'weekly']);
    DeliveryProviderCityFee::create(['organization_id' => $organization->id, 'delivery_provider_id' => $provider->id, 'city_name' => 'Casablanca', 'delivery_fee' => 25]);

    $shipment = dpfDeliveredShipment($store, $organization, 'Casablanca');
    $result = app(FinanceDeliveryProviderFeeCalculator::class)->calculateForOrder($shipment->shippable, $shipment);

    expect($result['delivery_fee'])->toBe(25.0)->and($result['source'])->toBe('city_fee');
});

it('falls back to the provider default fee when no city fee exists', function (): void {
    [, $store, $organization, $provider] = dpfWorkspace();
    DeliveryProviderFinanceSetting::create(['organization_id' => $organization->id, 'delivery_provider_id' => $provider->id, 'default_delivery_fee' => 30, 'payout_frequency' => 'weekly']);

    $shipment = dpfDeliveredShipment($store, $organization, 'Rabat');
    $result = app(FinanceDeliveryProviderFeeCalculator::class)->calculateForOrder($shipment->shippable, $shipment);

    expect($result['delivery_fee'])->toBe(30.0)->and($result['source'])->toBe('provider_default');
});

it('never applies an inactive city fee — falls back to the default fee instead', function (): void {
    [, $store, $organization, $provider] = dpfWorkspace();
    DeliveryProviderFinanceSetting::create(['organization_id' => $organization->id, 'delivery_provider_id' => $provider->id, 'default_delivery_fee' => 30, 'payout_frequency' => 'weekly']);
    DeliveryProviderCityFee::create(['organization_id' => $organization->id, 'delivery_provider_id' => $provider->id, 'city_name' => 'Agadir', 'delivery_fee' => 20, 'is_active' => false]);

    $shipment = dpfDeliveredShipment($store, $organization, 'Agadir');
    $result = app(FinanceDeliveryProviderFeeCalculator::class)->calculateForOrder($shipment->shippable, $shipment);

    expect($result['delivery_fee'])->toBe(30.0)->and($result['source'])->toBe('provider_default');
});

it('flags manual_required when neither a city fee nor a default fee is configured', function (): void {
    [, $store, $organization] = dpfWorkspace();

    $shipment = dpfDeliveredShipment($store, $organization, 'Fes');
    $result = app(FinanceDeliveryProviderFeeCalculator::class)->calculateForOrder($shipment->shippable, $shipment);

    expect($result['source'])->toBe('manual_required')
        ->and($result['delivery_fee'])->toBeNull()
        ->and($result['total_expected_fee'])->toBeNull();
});

it('stores a fee snapshot on the shipment once it is marked delivered', function (): void {
    [$owner, $store, $organization, $provider] = dpfWorkspace();
    DeliveryProviderFinanceSetting::create(['organization_id' => $organization->id, 'delivery_provider_id' => $provider->id, 'default_delivery_fee' => 40, 'payout_frequency' => 'weekly']);

    $order = Order::factory()->create(['store_id' => $store->id, 'organization_id' => $organization->id, 'total' => 500, 'platform_data' => [], 'fulfillment_status' => FulfillmentStatus::Confirmed]);
    $shipment = Shipment::create([
        'store_id' => $store->id, 'organization_id' => $organization->id,
        'shippable_type' => Order::class, 'shippable_id' => $order->id,
        'provider_code' => 'ozon', 'status' => Shipment::STATUS_SENT_TO_CARRIER,
        'city_name' => 'Tangier', 'receiver_name' => 'X', 'phone' => '0600000000', 'address' => 'Tangier',
    ]);

    expect($shipment->fee_calculated_at)->toBeNull();

    app(ShipmentTrackingService::class)->apply($shipment, 'delivered', Shipment::STATUS_DELIVERED, [], $owner);

    $shipment->refresh();
    expect($shipment->fee_calculated_at)->not->toBeNull()
        ->and((float) $shipment->expected_delivery_fee)->toBe(40.0)
        ->and($shipment->fee_source)->toBe('provider_default')
        ->and((float) $shipment->expected_total_carrier_fee)->toBeGreaterThanOrEqual(40.0);
});

it('keeps an already-computed fee snapshot unchanged after the provider tariff is updated later', function (): void {
    [$owner, $store, $organization, $provider] = dpfWorkspace();
    $settings = DeliveryProviderFinanceSetting::create(['organization_id' => $organization->id, 'delivery_provider_id' => $provider->id, 'default_delivery_fee' => 40, 'payout_frequency' => 'weekly']);

    $order = Order::factory()->create(['store_id' => $store->id, 'organization_id' => $organization->id, 'total' => 500, 'platform_data' => [], 'fulfillment_status' => FulfillmentStatus::Confirmed]);
    $shipment = Shipment::create([
        'store_id' => $store->id, 'organization_id' => $organization->id,
        'shippable_type' => Order::class, 'shippable_id' => $order->id,
        'provider_code' => 'ozon', 'status' => Shipment::STATUS_SENT_TO_CARRIER,
        'city_name' => 'Marrakech', 'receiver_name' => 'X', 'phone' => '0600000000', 'address' => 'Marrakech',
    ]);

    app(ShipmentTrackingService::class)->apply($shipment, 'delivered', Shipment::STATUS_DELIVERED, [], $owner);
    expect((float) $shipment->fresh()->expected_delivery_fee)->toBe(40.0);

    // The provider's tariff changes next month...
    $settings->update(['default_delivery_fee' => 99]);

    // ...but re-running the (idempotent) snapshot calculation never touches
    // the already-delivered order's stored fee.
    app(FinanceDeliveryProviderFeeCalculator::class)->snapshotForShipment($shipment->fresh());

    expect((float) $shipment->fresh()->expected_delivery_fee)->toBe(40.0);
});

it('rejects a bank account from another organization on provider settings', function (): void {
    [$ownerA, , , $providerA] = dpfWorkspace('DPF Reject A');
    [, , $orgB] = dpfWorkspace('DPF Reject B');
    $bankB = FinanceAccount::where('organization_id', $orgB->id)->where('type', 'bank')->firstOrFail();

    $this->actingAs($ownerA)->patch("/dashboard/finance/delivery-providers/{$providerA->id}", [
        'payout_frequency' => 'weekly', 'default_bank_account_id' => $bankB->id,
    ])->assertSessionHasErrors('default_bank_account_id');
});

it('never lets a user reach another organization\'s city fee', function (): void {
    [$ownerA] = dpfWorkspace('DPF CF Reject A');
    [, , $orgB, $providerB] = dpfWorkspace('DPF CF Reject B');
    $cityFeeB = DeliveryProviderCityFee::create(['organization_id' => $orgB->id, 'delivery_provider_id' => $providerB->id, 'city_name' => 'X', 'delivery_fee' => 10]);

    $this->actingAs($ownerA)->patch("/dashboard/finance/delivery-providers/{$providerB->id}/city-fees/{$cityFeeB->id}", [
        'city_id' => null, 'provider_city_id' => null, 'custom_city_name' => 'X', 'delivery_fee' => 50,
    ])->assertStatus(404);
});

/*
|--------------------------------------------------------------------------
| City fee selection must come from real city data, not free text
| (settlement: /dashboard/finance/delivery-providers/{provider}/city-fees)
|--------------------------------------------------------------------------
*/

function dpfProviderCity(Store $store, string $cityName, ?string $cityRef = null): DeliveryProviderCity
{
    return DeliveryProviderCity::create([
        'store_id' => $store->id, 'provider_code' => 'ozon',
        'provider_city_id' => $cityRef ?? strtoupper($cityName),
        'city_name' => $cityName, 'city_ref' => $cityRef ?? strtoupper($cityName),
        'delivered_price' => 22, 'returned_price' => 5, 'refused_price' => 5,
    ]);
}

it('creates a city fee from a provider-synced city selection, snapshotting name and code', function (): void {
    [$owner, $store, $organization, $provider] = dpfWorkspace();
    $providerCity = dpfProviderCity($store, 'Casablanca', 'CASA01');

    $this->actingAs($owner)->post("/dashboard/finance/delivery-providers/{$provider->id}/city-fees", [
        'provider_city_id' => $providerCity->id, 'delivery_fee' => 20,
    ])->assertSessionHasNoErrors()->assertRedirect();

    $fee = DeliveryProviderCityFee::where('organization_id', $organization->id)->firstOrFail();
    expect($fee->provider_city_id)->toBe($providerCity->id)
        ->and($fee->city_id)->toBeNull()
        ->and($fee->city_name)->toBe('Casablanca')
        ->and($fee->provider_city_code)->toBe('CASA01');
});

it('creates a city fee from a canonical (internal) city selection', function (): void {
    [$owner, , $organization, $provider] = dpfWorkspace();
    $city = City::where('country_code', 'MA')->where('is_active', true)->firstOrFail();

    $this->actingAs($owner)->post("/dashboard/finance/delivery-providers/{$provider->id}/city-fees", [
        'city_id' => $city->id, 'delivery_fee' => 18,
    ])->assertSessionHasNoErrors()->assertRedirect();

    $fee = DeliveryProviderCityFee::where('organization_id', $organization->id)->firstOrFail();
    expect($fee->city_id)->toBe($city->id)
        ->and($fee->provider_city_id)->toBeNull()
        ->and($fee->city_name)->toBe($city->name);
});

it('rejects a city fee with no city selected at all', function (): void {
    [$owner, , $organization, $provider] = dpfWorkspace();

    $this->actingAs($owner)->post("/dashboard/finance/delivery-providers/{$provider->id}/city-fees", [
        'delivery_fee' => 20,
    ])->assertSessionHasErrors('city_id');

    expect(DeliveryProviderCityFee::where('organization_id', $organization->id)->exists())->toBeFalse();
});

it('rejects raw misspelled city text from a non-owner staff member in the normal flow', function (): void {
    [, $store, $organization, $provider] = dpfWorkspace();
    $staff = dpfStaffWithPermissions($store, $organization, ['finance.view', 'finance.manage_cod_settlements']);

    $this->actingAs($staff)->post("/dashboard/finance/delivery-providers/{$provider->id}/city-fees", [
        'custom_city_name' => 'Casablanka', 'delivery_fee' => 20,
    ])->assertSessionHasErrors('custom_city_name');

    expect(DeliveryProviderCityFee::where('organization_id', $organization->id)->exists())->toBeFalse();
});

it('allows the admin-only custom city fallback for an owner', function (): void {
    [$owner, , $organization, $provider] = dpfWorkspace();

    $this->actingAs($owner)->post("/dashboard/finance/delivery-providers/{$provider->id}/city-fees", [
        'custom_city_name' => 'Somewhere Remote', 'delivery_fee' => 45,
    ])->assertSessionHasNoErrors()->assertRedirect();

    $fee = DeliveryProviderCityFee::where('organization_id', $organization->id)->firstOrFail();
    expect($fee->city_name)->toBe('Somewhere Remote')
        ->and($fee->city_id)->toBeNull()
        ->and($fee->provider_city_id)->toBeNull();
});

it('rejects a duplicate active city fee for the same provider and city', function (): void {
    [$owner, , $organization, $provider] = dpfWorkspace();
    $city = City::where('country_code', 'MA')->where('is_active', true)->firstOrFail();

    $this->actingAs($owner)->post("/dashboard/finance/delivery-providers/{$provider->id}/city-fees", [
        'city_id' => $city->id, 'delivery_fee' => 18,
    ])->assertSessionHasNoErrors();

    $this->actingAs($owner)->post("/dashboard/finance/delivery-providers/{$provider->id}/city-fees", [
        'city_id' => $city->id, 'delivery_fee' => 25,
    ])->assertSessionHasErrors('city_id');

    expect(DeliveryProviderCityFee::where('organization_id', $organization->id)->where('city_id', $city->id)->count())->toBe(1);
});

it('never lets a provider city id from another organization\'s store be used for a city fee', function (): void {
    [$ownerA] = dpfWorkspace('DPF Cross A');
    [, $storeB, , $providerB] = dpfWorkspace('DPF Cross B');
    $providerCityB = dpfProviderCity($storeB, 'Rabat', 'RAB01');

    $this->actingAs($ownerA)->post("/dashboard/finance/delivery-providers/{$providerB->id}/city-fees", [
        'provider_city_id' => $providerCityB->id, 'delivery_fee' => 20,
    ])->assertSessionHasErrors('provider_city_id');
});

it('fee calculator matches by provider_city_id when the shipment was routed to that exact provider city', function (): void {
    [, $store, $organization, $provider] = dpfWorkspace();
    DeliveryProviderFinanceSetting::create(['organization_id' => $organization->id, 'delivery_provider_id' => $provider->id, 'default_delivery_fee' => 30, 'payout_frequency' => 'weekly']);
    $providerCity = dpfProviderCity($store, 'Fes', 'FES01');
    // No delivered_price on this provider city, so the API-quote tier
    // (Tier 1) is skipped and the match must come from the city fee (Tier 2).
    $providerCity->update(['delivered_price' => null]);
    DeliveryProviderCityFee::create(['organization_id' => $organization->id, 'delivery_provider_id' => $provider->id, 'provider_city_id' => $providerCity->id, 'city_name' => 'Fes', 'delivery_fee' => 27]);

    $order = Order::factory()->create(['store_id' => $store->id, 'organization_id' => $organization->id, 'total' => 500, 'platform_data' => [], 'fulfillment_status' => FulfillmentStatus::Delivered]);
    $shipment = Shipment::create([
        'store_id' => $store->id, 'organization_id' => $organization->id, 'shippable_type' => Order::class, 'shippable_id' => $order->id,
        'provider_code' => 'ozon', 'status' => Shipment::STATUS_DELIVERED, 'city_id' => $providerCity->id, 'city_name' => 'Fes',
        'receiver_name' => 'X', 'phone' => '0600000000', 'address' => 'Fes', 'delivered_at' => now(),
    ]);

    $result = app(FinanceDeliveryProviderFeeCalculator::class)->calculateForOrder($order, $shipment);
    expect($result['delivery_fee'])->toBe(27.0)->and($result['source'])->toBe('city_fee')->and($result['metadata']['matched_by'])->toBe('provider_city_id');
});

it('fee calculator matches by canonical city_id when the order was confirmed against it and no provider city fee exists', function (): void {
    [, $store, $organization, $provider] = dpfWorkspace();
    DeliveryProviderFinanceSetting::create(['organization_id' => $organization->id, 'delivery_provider_id' => $provider->id, 'default_delivery_fee' => 30, 'payout_frequency' => 'weekly']);
    $city = City::where('country_code', 'MA')->where('is_active', true)->firstOrFail();
    DeliveryProviderCityFee::create(['organization_id' => $organization->id, 'delivery_provider_id' => $provider->id, 'city_id' => $city->id, 'city_name' => $city->name, 'delivery_fee' => 19]);

    $order = Order::factory()->create([
        'store_id' => $store->id, 'organization_id' => $organization->id, 'total' => 500, 'platform_data' => [],
        'fulfillment_status' => FulfillmentStatus::Delivered, 'shipping_city_id' => $city->id,
    ]);
    // Shipment has no city_id at all (provider sync incomplete) — the
    // calculator must fall through to the order's own canonical city.
    $shipment = Shipment::create([
        'store_id' => $store->id, 'organization_id' => $organization->id, 'shippable_type' => Order::class, 'shippable_id' => $order->id,
        'provider_code' => 'ozon', 'status' => Shipment::STATUS_DELIVERED, 'city_name' => $city->name,
        'receiver_name' => 'X', 'phone' => '0600000000', 'address' => $city->name, 'delivered_at' => now(),
    ]);

    $result = app(FinanceDeliveryProviderFeeCalculator::class)->calculateForOrder($order, $shipment);
    expect($result['delivery_fee'])->toBe(19.0)->and($result['source'])->toBe('city_fee')->and($result['metadata']['matched_by'])->toBe('city_id');
});

/*
|--------------------------------------------------------------------------
| UI stays compact — settings/city-fee forms hidden behind Configure
| (same file-source-inspection pattern as IntegrationNavigationTest, since
| this app has no JS test runner to render the component itself).
|--------------------------------------------------------------------------
*/

it('renders the provider list as a compact table, with settings hidden behind a Configure modal', function (): void {
    $source = file_get_contents(resource_path('js/Pages/Dashboard/Finance/DeliveryProviders/Index.jsx'));

    // The main list is one compact DataTable of summary rows — not a full
    // settings form rendered inline for every provider.
    expect($source)->toContain('DataTable columns={columns} data={providers}')
        ->toContain("setConfiguring({ provider: p, tab: 'cod' }")
        // The heavy configuration UI (COD settings / default fees / city
        // fees) only mounts once a specific provider is selected.
        ->toContain('{configuring && (')
        ->toContain('<ConfigureModal')
        // City selection is a searchable component, never a raw text input.
        ->toContain('<CitySearchSelect')
        ->not->toContain('<input value={data.city_name}');
});

it('tariff changes never mutate an already-computed fee snapshot, even with the new id-based matching', function (): void {
    [$owner, $store, $organization, $provider] = dpfWorkspace();
    $settings = DeliveryProviderFinanceSetting::create(['organization_id' => $organization->id, 'delivery_provider_id' => $provider->id, 'default_delivery_fee' => 30, 'payout_frequency' => 'weekly']);
    $city = City::where('country_code', 'MA')->where('is_active', true)->firstOrFail();
    $cityFee = DeliveryProviderCityFee::create(['organization_id' => $organization->id, 'delivery_provider_id' => $provider->id, 'city_id' => $city->id, 'city_name' => $city->name, 'delivery_fee' => 19]);

    $order = Order::factory()->create([
        'store_id' => $store->id, 'organization_id' => $organization->id, 'total' => 500, 'platform_data' => [],
        'fulfillment_status' => FulfillmentStatus::Confirmed, 'shipping_city_id' => $city->id,
    ]);
    $shipment = Shipment::create([
        'store_id' => $store->id, 'organization_id' => $organization->id, 'shippable_type' => Order::class, 'shippable_id' => $order->id,
        'provider_code' => 'ozon', 'status' => Shipment::STATUS_SENT_TO_CARRIER, 'city_name' => $city->name,
        'receiver_name' => 'X', 'phone' => '0600000000', 'address' => $city->name,
    ]);

    app(ShipmentTrackingService::class)->apply($shipment, 'delivered', Shipment::STATUS_DELIVERED, [], $owner);
    expect((float) $shipment->fresh()->expected_delivery_fee)->toBe(19.0);

    $cityFee->update(['delivery_fee' => 99]);
    $settings->update(['default_delivery_fee' => 150]);
    app(FinanceDeliveryProviderFeeCalculator::class)->snapshotForShipment($shipment->fresh());

    expect((float) $shipment->fresh()->expected_delivery_fee)->toBe(19.0);
});
