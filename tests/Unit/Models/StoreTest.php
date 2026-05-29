<?php

declare(strict_types=1);

use App\Enums\StoreStatus;
use App\Enums\StoreType;
use App\Models\Store;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

it('isOnline returns true for online type', function (): void {
    $store = Store::factory()->online()->make();

    expect($store->isOnline())->toBeTrue()
        ->and($store->isPhysical())->toBeFalse()
        ->and($store->isHybrid())->toBeFalse();
});

it('isPhysical returns true for physical type', function (): void {
    $store = Store::factory()->physical()->make();

    expect($store->isPhysical())->toBeTrue()
        ->and($store->isOnline())->toBeFalse()
        ->and($store->isHybrid())->toBeFalse();
});

it('isHybrid returns true for hybrid type', function (): void {
    $store = Store::factory()->hybrid()->make();

    expect($store->isHybrid())->toBeTrue()
        ->and($store->isOnline())->toBeFalse()
        ->and($store->isPhysical())->toBeFalse();
});

it('isActive returns true when status is active', function (): void {
    $store = Store::factory()->make(['status' => StoreStatus::Active]);

    expect($store->isActive())->toBeTrue();
});

it('isActive returns false when status is inactive', function (): void {
    $store = Store::factory()->inactive()->make();

    expect($store->isActive())->toBeFalse();
});

it('auto-generates slug from name on create', function (): void {
    $user  = User::factory()->create();
    $store = Store::factory()->create([
        'user_id' => $user->id,
        'name'    => 'My Test Store',
        'slug'    => '',
    ]);

    expect($store->slug)->toContain('my-test-store');
});

it('slug is unique across stores with the same name', function (): void {
    $user = User::factory()->create();

    $first  = Store::factory()->create(['user_id' => $user->id, 'name' => 'Dupe Store', 'slug' => '']);
    $second = Store::factory()->create(['user_id' => $user->id, 'name' => 'Dupe Store', 'slug' => '']);

    expect($first->slug)->not->toBe($second->slug);
});

it('store belongs to a user', function (): void {
    $user  = User::factory()->create();
    $store = Store::factory()->create(['user_id' => $user->id]);

    expect($store->user->id)->toBe($user->id);
});

it('settings cast to array', function (): void {
    $store = Store::factory()->make(['settings' => ['key' => 'value']]);

    expect($store->settings)->toBeArray()
        ->and($store->settings['key'])->toBe('value');
});

it('business_rules cast to array', function (): void {
    $store = Store::factory()->make(['business_rules' => ['min_order' => 50]]);

    expect($store->business_rules)->toBeArray()
        ->and($store->business_rules['min_order'])->toBe(50);
});

it('metadata cast to array', function (): void {
    $store = Store::factory()->make(['metadata' => ['source' => 'import']]);

    expect($store->metadata)->toBeArray()
        ->and($store->metadata['source'])->toBe('import');
});

it('type is cast to StoreType enum', function (): void {
    $store = Store::factory()->online()->make();

    expect($store->type)->toBe(StoreType::Online);
});

it('status is cast to StoreStatus enum', function (): void {
    $store = Store::factory()->make();

    expect($store->status)->toBe(StoreStatus::Active);
});
