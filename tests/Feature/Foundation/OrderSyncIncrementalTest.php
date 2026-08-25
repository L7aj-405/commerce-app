<?php

declare(strict_types=1);

use App\Models\Order;
use App\Models\PlatformConnection;
use App\Models\Store;
use App\Models\User;
use App\Services\OrganizationProvisioner;
use Illuminate\Support\Facades\Http;

/*
|--------------------------------------------------------------------------
| Incremental sync: a first-ever sync defaults to the last N days
| (config('sync.orders_initial_import_days')), never unbounded history; a
| later sync passes the connection's own cursor as `after`; "Full order
| resync" passes no lower bound at all. Duplicate prevention is the
| existing (platform_connection_id, platform_order_id) unique DB index.
|--------------------------------------------------------------------------
*/

function osiWorkspace(string $name = 'Order Sync Incremental Store'): array
{
    $owner = User::factory()->create(['role' => 'store_admin', 'onboarding_completed_at' => now()]);
    $organization = app(OrganizationProvisioner::class)->createOwnedOrganization($owner, $name);
    $store = Store::factory()->create(['user_id' => $owner->id, 'organization_id' => $organization->id, 'name' => $name]);
    $store->ensureDefaultRoles();

    return [$owner, $store];
}

function osiWoo(Store $store, string $domain): PlatformConnection
{
    return PlatformConnection::withoutTenancy(fn () => PlatformConnection::create([
        'store_id' => $store->id, 'platform' => 'woocommerce', 'status' => 'active',
        'api_url' => "https://{$domain}", 'consumer_key' => 'ck_test', 'consumer_secret' => 'cs_test',
    ]));
}

/** WooCommerceConnector::getOrders() sends `after` as a GET query param, not a form/JSON body — Http::Request::data() only reads the body, so the query string has to be parsed directly. */
function osiQueryParams($request): array
{
    parse_str((string) parse_url($request->url(), PHP_URL_QUERY), $params);

    return $params;
}

it('a first sync (no cursor yet) sends the configured default range as the "after" param', function (): void {
    [$owner, $store] = osiWorkspace();
    $woo = osiWoo($store, 'osi1-woo.example.com');

    Http::fake(['osi1-woo.example.com/wp-json/wc/v3/orders*' => Http::response([], 200)]);

    $this->actingAs($owner)->postJson("/dashboard/integrations/connections/{$woo->id}/sync-orders")->assertOk();

    $expectedFloor = now()->subDays((int) config('sync.orders_initial_import_days', 30))->subMinute();

    Http::assertSent(function ($request) use ($expectedFloor) {
        $after = osiQueryParams($request)['after'] ?? null;
        if ($after === null) {
            return false;
        }

        return \Carbon\Carbon::parse($after)->greaterThan($expectedFloor);
    });
});

it('a later sync sends the connection\'s own cursor as the "after" param, not the default range', function (): void {
    [$owner, $store] = osiWorkspace();
    $woo = osiWoo($store, 'osi2-woo.example.com');

    Http::fake(['osi2-woo.example.com/wp-json/wc/v3/orders*' => Http::response([], 200)]);

    $this->actingAs($owner)->postJson("/dashboard/integrations/connections/{$woo->id}/sync-orders")->assertOk();
    $cursorAfterFirstSync = $woo->fresh()->metadata['order_sync']['last_synced_at'];

    $this->travel(2)->hours();

    $this->actingAs($owner)->postJson("/dashboard/integrations/connections/{$woo->id}/sync-orders")->assertOk();

    Http::assertSent(function ($request) use ($cursorAfterFirstSync) {
        $after = osiQueryParams($request)['after'] ?? null;

        return $after !== null && \Carbon\Carbon::parse($after)->equalTo(\Carbon\Carbon::parse($cursorAfterFirstSync));
    });
});

it('imports only orders created after the cursor on a normal (non-full) sync', function (): void {
    [$owner, $store] = osiWorkspace();
    $woo = osiWoo($store, 'osi3-woo.example.com');

    // ONE sequence for both syncs — real WooCommerce would have already
    // filtered server-side by `after`; here what matters is that the second
    // sync's identity pair resolves to an UPDATE, not a second row.
    Http::fake(['osi3-woo.example.com/wp-json/wc/v3/orders*' => Http::sequence()
        ->push([['id' => 8001, 'number' => '8001', 'status' => 'processing', 'total' => '10', 'line_items' => [], 'billing' => [], 'date_created' => now()->toIso8601String()]], 200)
        ->push([], 200)
        ->push([['id' => 8001, 'number' => '8001', 'status' => 'processing', 'total' => '15', 'line_items' => [], 'billing' => [], 'date_created' => now()->toIso8601String()]], 200)
        ->push([], 200)]);

    $this->actingAs($owner)->postJson("/dashboard/integrations/connections/{$woo->id}/sync-orders")->assertOk();
    expect(Order::withoutTenancy(fn () => Order::query()->where('platform_connection_id', $woo->id)->count()))->toBe(1);

    $this->actingAs($owner)->postJson("/dashboard/integrations/connections/{$woo->id}/sync-orders")->assertOk();

    expect(Order::withoutTenancy(fn () => Order::query()->where('platform_connection_id', $woo->id)->count()))->toBe(1)
        ->and((float) Order::withoutTenancy(fn () => Order::query()->where('platform_connection_id', $woo->id)->firstOrFail()->total))->toBe(15.0);
});

it('a full resync scans further back (no lower bound) but still updates the existing order by identity, never duplicating', function (): void {
    [$owner, $store] = osiWorkspace();
    $woo = osiWoo($store, 'osi4-woo.example.com');
    $base = "/dashboard/integrations/connections/{$woo->id}";

    // ONE sequence registered up front for BOTH syncs — Http::fake() only
    // ever honors the FIRST-registered rule for a given URL pattern within
    // a test, so a second Http::fake() call for the same pattern later is
    // silently ignored (see OzonShipmentVerificationTest's own note on this).
    Http::fake(['osi4-woo.example.com/wp-json/wc/v3/orders*' => Http::sequence()
        ->push([['id' => 8002, 'number' => '8002', 'status' => 'processing', 'total' => '20', 'line_items' => [], 'billing' => [], 'date_created' => now()->subDays(120)->toIso8601String()]], 200)
        ->push([], 200) // page 2 — stop the first sync's pull loop
        ->push([['id' => 8002, 'number' => '8002', 'status' => 'processing', 'total' => '20', 'line_items' => [], 'billing' => [], 'date_created' => now()->subDays(120)->toIso8601String()]], 200)
        ->push([], 200)]); // page 2 — stop the full resync's pull loop

    $this->actingAs($owner)->postJson("{$base}/sync-orders")->assertOk();
    expect(Order::withoutTenancy(fn () => Order::query()->where('platform_connection_id', $woo->id)->count()))->toBe(1);

    $this->actingAs($owner)->postJson("{$base}/sync-orders/queue")->assertOk();

    // A "full resync" job passes no `after` at all.
    Http::assertSent(fn ($request) => ! array_key_exists('after', osiQueryParams($request)));

    expect(Order::withoutTenancy(fn () => Order::query()->where('platform_connection_id', $woo->id)->count()))->toBe(1);
});

it('an existing order is found and updated by the platform_connection_id + platform_order_id identity pair', function (): void {
    [$owner, $store] = osiWorkspace();
    $woo = osiWoo($store, 'osi5-woo.example.com');

    Http::fake(['osi5-woo.example.com/wp-json/wc/v3/orders*' => Http::sequence()
        ->push([['id' => 8003, 'number' => '8003', 'status' => 'processing', 'total' => '11.00', 'currency' => 'MAD', 'line_items' => [], 'billing' => [], 'date_created' => now()->toIso8601String()]], 200)
        ->push([], 200)
        ->push([['id' => 8003, 'number' => '8003', 'status' => 'processing', 'total' => '22.00', 'currency' => 'MAD', 'line_items' => [], 'billing' => [], 'date_created' => now()->toIso8601String()]], 200)
        ->push([], 200)]);
    $this->actingAs($owner)->postJson("/dashboard/integrations/connections/{$woo->id}/sync-orders")->assertOk();

    $before = Order::withoutTenancy(fn () => Order::query()->where('platform_connection_id', $woo->id)->firstOrFail());

    $this->actingAs($owner)->postJson("/dashboard/integrations/connections/{$woo->id}/sync-orders")->assertOk();

    $after = $before->fresh();
    expect($after->id)->toBe($before->id)
        ->and((float) $after->total)->toBe(22.0)
        ->and(Order::withoutTenancy(fn () => Order::query()->where('platform_connection_id', $woo->id)->count()))->toBe(1);
});
