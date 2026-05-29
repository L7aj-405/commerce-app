<?php

declare(strict_types=1);

use App\Enums\OrderStatus;
use App\Livewire\Orders\OrderDetails;
use App\Livewire\Orders\OrderIndex;
use App\Models\Order;
use App\Models\Store;
use App\Models\User;
use Livewire\Livewire;

it('user can view their own orders', function (): void {
    $user  = User::factory()->create();
    $store = Store::factory()->create(['user_id' => $user->id]);
    $order = Order::factory()->pending()->create(['store_id' => $store->id]);

    Livewire::actingAs($user)
        ->test(OrderIndex::class)
        ->assertOk()
        ->assertSee($order->order_number);
});

it('user cannot see orders from another users store', function (): void {
    $user       = User::factory()->create();
    $otherUser  = User::factory()->create();
    $otherStore = Store::factory()->create(['user_id' => $otherUser->id]);
    $otherOrder = Order::factory()->create(['store_id' => $otherStore->id]);

    Livewire::actingAs($user)
        ->test(OrderIndex::class)
        ->assertOk()
        ->assertDontSee($otherOrder->order_number);
});

it('can filter orders by status', function (): void {
    $user      = User::factory()->create();
    $store     = Store::factory()->create(['user_id' => $user->id]);
    $pending   = Order::factory()->pending()->create(['store_id' => $store->id]);
    $confirmed = Order::factory()->confirmed()->create(['store_id' => $store->id]);

    Livewire::actingAs($user)
        ->test(OrderIndex::class)
        ->set('statusFilter', 'pending')
        ->assertSee($pending->order_number)
        ->assertDontSee($confirmed->order_number);
});

it('can mark order as confirmed from the list', function (): void {
    $user  = User::factory()->create();
    $store = Store::factory()->create(['user_id' => $user->id]);
    $order = Order::factory()->pending()->create(['store_id' => $store->id]);

    Livewire::actingAs($user)
        ->test(OrderIndex::class)
        ->call('markAsConfirmed', $order->id);

    expect($order->fresh()->status)->toBe(OrderStatus::Confirmed);
});

it('can mark order as cancelled from the list', function (): void {
    $user  = User::factory()->create();
    $store = Store::factory()->create(['user_id' => $user->id]);
    $order = Order::factory()->pending()->create(['store_id' => $store->id]);

    Livewire::actingAs($user)
        ->test(OrderIndex::class)
        ->call('markAsCancelled', $order->id);

    expect($order->fresh()->status)->toBe(OrderStatus::Cancelled);
});

it('cannot mark another users order as confirmed', function (): void {
    $user       = User::factory()->create();
    $otherUser  = User::factory()->create();
    $otherStore = Store::factory()->create(['user_id' => $otherUser->id]);
    $otherOrder = Order::factory()->pending()->create(['store_id' => $otherStore->id]);

    Livewire::actingAs($user)
        ->test(OrderIndex::class)
        ->call('markAsConfirmed', $otherOrder->id)
        ->assertForbidden();

    expect($otherOrder->fresh()->status)->toBe(OrderStatus::Pending);
});

it('user can view their own order details', function (): void {
    $user  = User::factory()->create();
    $store = Store::factory()->create(['user_id' => $user->id]);
    $order = Order::factory()->create(['store_id' => $store->id]);

    Livewire::actingAs($user)
        ->test(OrderDetails::class, ['order' => $order])
        ->assertOk()
        ->assertSee($order->order_number)
        ->assertSee($order->customer_name);
});

it('user cannot view order details from another users store', function (): void {
    $user       = User::factory()->create();
    $otherUser  = User::factory()->create();
    $otherStore = Store::factory()->create(['user_id' => $otherUser->id]);
    $otherOrder = Order::factory()->create(['store_id' => $otherStore->id]);

    Livewire::actingAs($user)
        ->test(OrderDetails::class, ['order' => $otherOrder])
        ->assertForbidden();
});

it('can mark order as confirmed from details page', function (): void {
    $user  = User::factory()->create();
    $store = Store::factory()->create(['user_id' => $user->id]);
    $order = Order::factory()->pending()->create(['store_id' => $store->id]);

    Livewire::actingAs($user)
        ->test(OrderDetails::class, ['order' => $order])
        ->call('markAsConfirmed');

    expect($order->fresh()->status)->toBe(OrderStatus::Confirmed)
        ->and($order->fresh()->whatsapp_confirmed_at)->not->toBeNull();
});

it('can mark order as cancelled from details page', function (): void {
    $user  = User::factory()->create();
    $store = Store::factory()->create(['user_id' => $user->id]);
    $order = Order::factory()->pending()->create(['store_id' => $store->id]);

    Livewire::actingAs($user)
        ->test(OrderDetails::class, ['order' => $order])
        ->call('markAsCancelled');

    expect($order->fresh()->status)->toBe(OrderStatus::Cancelled)
        ->and($order->fresh()->whatsapp_cancelled_at)->not->toBeNull();
});
