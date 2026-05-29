<?php

declare(strict_types=1);

use App\Enums\OrderStatus;
use App\Models\Order;
use App\Models\PlatformConnection;
use App\Models\Store;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

it('isPending returns true for pending status', function (): void {
    $order = Order::factory()->pending()->make();

    expect($order->isPending())->toBeTrue()
        ->and($order->isConfirmed())->toBeFalse()
        ->and($order->isCancelled())->toBeFalse();
});

it('isConfirmed returns true for confirmed status', function (): void {
    $order = Order::factory()->confirmed()->make();

    expect($order->isConfirmed())->toBeTrue()
        ->and($order->isPending())->toBeFalse()
        ->and($order->isCancelled())->toBeFalse();
});

it('isCancelled returns true for cancelled status', function (): void {
    $order = Order::factory()->cancelled()->make();

    expect($order->isCancelled())->toBeTrue()
        ->and($order->isPending())->toBeFalse()
        ->and($order->isConfirmed())->toBeFalse();
});

it('status is cast to OrderStatus enum', function (): void {
    $order = Order::factory()->pending()->make();

    expect($order->status)->toBe(OrderStatus::Pending);
});

it('items are cast to array', function (): void {
    $items = [['id' => '1', 'name' => 'Widget', 'quantity' => 2, 'price' => 99.0]];
    $order = Order::factory()->make(['items' => $items]);

    expect($order->items)->toBeArray()
        ->and($order->items[0]['name'])->toBe('Widget');
});

it('platform_data is cast to array', function (): void {
    $order = Order::factory()->make(['platform_data' => ['raw_id' => 42]]);

    expect($order->platform_data)->toBeArray()
        ->and($order->platform_data['raw_id'])->toBe(42);
});

it('needsConfirmation returns true for pending order with phone', function (): void {
    $order = Order::factory()->pending()->make(['customer_phone' => '+212612345678']);

    expect($order->needsConfirmation())->toBeTrue();
});

it('needsConfirmation returns false when phone is null', function (): void {
    $order = Order::factory()->pending()->make(['customer_phone' => null]);

    expect($order->needsConfirmation())->toBeFalse();
});

it('needsConfirmation returns false when order is not pending', function (): void {
    $order = Order::factory()->confirmed()->make(['customer_phone' => '+212612345678']);

    expect($order->needsConfirmation())->toBeFalse();
});

it('markAsConfirmed sets confirmed status and timestamp', function (): void {
    $order = Order::factory()->pending()->create();

    $order->markAsConfirmed();

    expect($order->fresh()->status)->toBe(OrderStatus::Confirmed)
        ->and($order->fresh()->whatsapp_confirmed_at)->not->toBeNull();
});

it('markAsCancelled sets cancelled status and timestamp', function (): void {
    $order = Order::factory()->pending()->create();

    $order->markAsCancelled();

    expect($order->fresh()->status)->toBe(OrderStatus::Cancelled)
        ->and($order->fresh()->whatsapp_cancelled_at)->not->toBeNull();
});

it('order belongs to a store', function (): void {
    $user  = User::factory()->create();
    $store = Store::factory()->create(['user_id' => $user->id]);
    $order = Order::factory()->create(['store_id' => $store->id]);

    expect($order->store->id)->toBe($store->id);
});

it('order belongs to a platform connection', function (): void {
    $user       = User::factory()->create();
    $store      = Store::factory()->create(['user_id' => $user->id]);
    $connection = PlatformConnection::create([
        'store_id' => $store->id,
        'platform' => 'woocommerce',
        'status'   => 'active',
    ]);
    $order = Order::factory()->create([
        'store_id'               => $store->id,
        'platform_connection_id' => $connection->id,
    ]);

    expect($order->platformConnection->id)->toBe($connection->id);
});

it('order platform connection can be null', function (): void {
    $order = Order::factory()->create(['platform_connection_id' => null]);

    expect($order->platformConnection)->toBeNull();
});
