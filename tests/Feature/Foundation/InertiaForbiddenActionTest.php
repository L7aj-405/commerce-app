<?php

declare(strict_types=1);

use App\Enums\FulfillmentStatus;
use App\Events\OrderCreated;
use App\Models\Order;
use App\Models\PlatformConnection;
use App\Models\Store;
use App\Models\StoreMember;
use App\Models\User;
use App\Services\OrganizationProvisioner;
use App\Services\Sync\OrderSyncService;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Queue;

/**
 * App\Support\InertiaErrorResponder: a genuine Inertia SPA action (the
 * X-Inertia request header — set by every router.post/put/patch/delete call
 * once the app has loaded, exactly what Confirmation.jsx/Manage.jsx use)
 * that gets rejected with 401/403/419 must redirect back with a flash error
 * instead of blowing the user off the page with a bare error screen.
 *
 * Critically, this is gated STRICTLY on that header — a plain HTTP request
 * (no X-Inertia header, e.g. a Pest test or a non-JS caller) to the exact
 * same route keeps getting the original, unmodified 403 response. See
 * OrderActionAuthorizationUxTest for proof of that unchanged path.
 */

/** @return array{0: User, 1: Store} */
function ifatWorkspace(string $name = 'Inertia Forbidden Store'): array
{
    $owner = User::factory()->create(['role' => 'store_admin', 'onboarding_completed_at' => now()]);
    $organization = app(OrganizationProvisioner::class)->createOwnedOrganization($owner, $name);
    $store = Store::factory()->create(['user_id' => $owner->id, 'organization_id' => $organization->id, 'name' => $name]);
    $store->ensureDefaultRoles();

    return [$owner, $store];
}

function ifatMember(Store $store, string $roleSlug): User
{
    $member = User::factory()->create(['role' => 'store_admin', 'onboarding_completed_at' => now()]);
    StoreMember::create([
        'store_id' => $store->id, 'user_id' => $member->id, 'role' => 'manager',
        'store_role_id' => $store->roles()->where('slug', $roleSlug)->firstOrFail()->id,
        'is_active' => true, 'joined_at' => now(),
    ]);
    app(OrganizationProvisioner::class)->ensureMember($store->organization, $member);

    return $member;
}

function ifatOnlineOrder(Store $store, string $externalId = 'IFAT-1'): Order
{
    $connection = PlatformConnection::withoutTenancy(fn () => PlatformConnection::create([
        'store_id' => $store->id, 'platform' => 'woocommerce', 'status' => 'active', 'api_url' => "https://{$externalId}.example.com",
    ]));

    return app(OrderSyncService::class)->saveOrder([
        'platform_id' => $externalId, 'number' => "#{$externalId}", 'status' => 'processing', 'total' => 100.0, 'currency' => 'MAD',
        'customer_name' => 'Inertia Customer', 'customer_email' => null, 'customer_phone' => null,
        'items' => [], 'created_at' => now()->toIso8601String(), 'platform_data' => [],
    ], $connection);
}

beforeEach(function (): void {
    Event::fake([OrderCreated::class]);
    Queue::fake();
});

it('redirects back with a flash error instead of a bare 403 page for a genuine Inertia action', function (): void {
    [, $store] = ifatWorkspace();
    $agent = ifatMember($store, 'confirmation-agent');
    $order = ifatOnlineOrder($store);

    // Headers passed as post()'s 3rd argument are scoped to THIS call only —
    // unlike withHeaders(), which would leak X-Inertia onto every later
    // request in the test and trip Inertia's own version-mismatch guard.
    $response = $this->actingAs($agent)
        ->post("/dashboard/orders/online/{$order->id}/status", ['status' => 'confirmed'], ['X-Inertia' => 'true']);

    $response->assertRedirect();
    expect($response->getStatusCode())->not->toBe(403);
    expect(session('error'))->toBe('Claim this order before confirming or cancelling it.');
    expect($order->fresh()->fulfillment_status)->toBe(FulfillmentStatus::Pending);
});

it('exposes the flash error to Inertia shared props on the very next request', function (): void {
    [, $store] = ifatWorkspace();
    $agent = ifatMember($store, 'confirmation-agent');
    $order = ifatOnlineOrder($store);

    $this->actingAs($agent)
        ->post("/dashboard/orders/online/{$order->id}/status", ['status' => 'confirmed'], ['X-Inertia' => 'true'])
        ->assertRedirect();

    $this->actingAs($agent)->get('/dashboard/orders/manage')
        ->assertOk()
        ->assertInertia(fn ($page) => $page->where('flash.error', 'Claim this order before confirming or cancelling it.'));
});

it('does NOT change the response for the exact same action without the X-Inertia header (plain HTTP callers keep the original 403)', function (): void {
    [, $store] = ifatWorkspace();
    $agent = ifatMember($store, 'confirmation-agent');
    $order = ifatOnlineOrder($store);

    $this->actingAs($agent)
        ->post("/dashboard/orders/online/{$order->id}/status", ['status' => 'confirmed'])
        ->assertForbidden();
});

it('does not convert a real page-level GET 403 into a redirect or a silent success', function (): void {
    // The "stay on page" treatment is gated on isMethod('GET') === false — a
    // GET request (real page navigation) always falls through to the
    // branded-error-page path (see BrandedErrorPageTest), never a redirect,
    // regardless of whether it carries an X-Inertia header.
    [, $store] = ifatWorkspace('Inertia Forbidden GET Store');
    $manager = ifatMember($store, 'manager');

    $this->actingAs($manager)->get('/dashboard/roles')->assertForbidden();
});
