<?php

declare(strict_types=1);

use App\Models\Product;
use App\Models\Store;
use App\Models\SupportSession;
use App\Models\User;
use App\Services\OrganizationProvisioner;
use App\Services\SupportAccess;

function supportTestWorkspace(string $name = 'Client Workspace'): array
{
    $owner = User::factory()->create([
        'role' => 'store_admin',
        'onboarding_completed_at' => now(),
    ]);

    $organization = app(OrganizationProvisioner::class)->createOwnedOrganization($owner, $name);
    $store = Store::factory()->create([
        'user_id' => $owner->id,
        'organization_id' => $organization->id,
        'name' => $name . ' Store',
    ]);
    $store->ensureDefaultRoles();

    return [$owner, $organization, $store];
}


function supportTestProduct(Store $store, string $sku): Product
{
    return Product::withoutTenancy(fn () => Product::create([
        'store_id' => $store->id,
        'name' => $sku,
        'sku' => $sku,
        'type' => 'simple',
        'status' => 'active',
        'price' => 100,
    ]));
}

it('does not give a platform super admin permanent tenant access', function (): void {
    [, , $store] = supportTestWorkspace();
    $admin = User::factory()->create([
        'role' => 'super_admin',
        'onboarding_completed_at' => now(),
    ]);

    supportTestProduct($store, 'NO-SUPPORT-001');

    $this->actingAs($admin);

    expect($admin->accessibleStores())->toHaveCount(0)
        ->and($admin->isPrivilegedFor($store))->toBeFalse()
        ->and($admin->hasStorePermission($store, 'orders.view'))->toBeFalse()
        ->and($admin->canAccessDashboard($store))->toBeFalse()
        ->and(Product::query()->count())->toBe(0);
});

it('starts a temporary support session scoped to exactly one client store', function (): void {
    [$owner, $organization, $store] = supportTestWorkspace('Atlas Client');
    [, , $otherStore] = supportTestWorkspace('Other Client');
    $product = supportTestProduct($store, 'SUPPORT-A-001');
    $foreignProduct = supportTestProduct($otherStore, 'SUPPORT-B-001');

    $admin = User::factory()->create([
        'role' => 'super_admin',
        'onboarding_completed_at' => now(),
    ]);

    $this->actingAs($admin)
        ->post("/admin/clients/{$owner->id}/stores/{$store->id}/support", [
            'reason' => 'Diagnose WooCommerce synchronization failure',
            'duration' => 30,
        ])
        ->assertRedirect('/dashboard');

    $support = SupportSession::query()->latest('created_at')->firstOrFail();

    expect($support->user_id)->toBe($admin->id)
        ->and($support->organization_id)->toBe($organization->id)
        ->and($support->store_id)->toBe($store->id)
        ->and($support->ended_at)->toBeNull()
        ->and(session(SupportAccess::SESSION_KEY))->toBe($support->id)
        ->and($admin->fresh()->accessibleStores()->pluck('id')->all())->toBe([$store->id])
        ->and($admin->fresh()->isPrivilegedFor($store))->toBeTrue()
        ->and($admin->fresh()->isPrivilegedFor($otherStore))->toBeFalse()
        ->and($admin->fresh()->canAccessPos($store))->toBeFalse()
        ->and(Product::query()->pluck('id')->all())->toBe([$product->id]);

    // Route-model binding may run before ResolveTenant. The fallback tenant
    // resolution must still reject a foreign product id before the controller.
    $this->get("/dashboard/products/{$foreignProduct->id}/edit")
        ->assertNotFound();
});

it('cannot start support for a store that does not belong to the selected client', function (): void {
    [$client] = supportTestWorkspace('Client A');
    [, , $foreignStore] = supportTestWorkspace('Client B');

    $admin = User::factory()->create(['role' => 'super_admin']);

    $this->actingAs($admin)
        ->post("/admin/clients/{$client->id}/stores/{$foreignStore->id}/support", [
            'reason' => 'Attempted cross-client support access',
            'duration' => 15,
        ])
        ->assertNotFound();

    expect(SupportSession::query()->count())->toBe(0);
});

it('ends support mode and removes tenant access immediately', function (): void {
    [$owner, , $store] = supportTestWorkspace();
    $admin = User::factory()->create(['role' => 'super_admin']);

    $this->actingAs($admin)
        ->post("/admin/clients/{$owner->id}/stores/{$store->id}/support", [
            'reason' => 'Fix store setup configuration',
            'duration' => 60,
        ])
        ->assertRedirect('/dashboard');

    $this->delete('/admin/support')
        ->assertRedirect("/admin/clients/{$owner->id}");

    $support = SupportSession::query()->latest('created_at')->firstOrFail();

    expect($support->ended_at)->not->toBeNull()
        ->and($support->end_reason)->toBe('manual')
        ->and(session(SupportAccess::SESSION_KEY))->toBeNull()
        ->and($admin->fresh()->accessibleStores())->toHaveCount(0);
});

it('expires stale support sessions instead of restoring access', function (): void {
    [$owner, , $store] = supportTestWorkspace();
    $admin = User::factory()->create(['role' => 'super_admin']);

    $this->actingAs($admin)
        ->post("/admin/clients/{$owner->id}/stores/{$store->id}/support", [
            'reason' => 'Temporary troubleshooting window',
            'duration' => 15,
        ]);

    SupportSession::query()->latest('created_at')->firstOrFail()->update([
        'expires_at' => now()->subMinute(),
    ]);

    // Resolve a fresh scoped service after changing the persisted expiry.
    app()->forgetInstance(SupportAccess::class);

    expect($admin->fresh()->accessibleStores())->toHaveCount(0)
        ->and(session(SupportAccess::SESSION_KEY))->toBeNull();

    $support = SupportSession::query()->latest('created_at')->firstOrFail();
    expect($support->ended_at)->not->toBeNull()
        ->and($support->end_reason)->toBe('expired');
});
