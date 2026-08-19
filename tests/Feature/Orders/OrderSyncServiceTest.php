<?php

declare(strict_types=1);

use App\Connectors\WooCommerceConnector;
use App\Enums\FulfillmentStatus;
use App\Enums\OrderStatus;
use App\Events\OrderCreated;
use App\Jobs\SendWhatsAppConfirmation;
use App\Models\Order;
use App\Models\PlatformConnection;
use App\Models\Store;
use App\Models\User;
use App\Services\Sync\OrderSyncService;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Queue;

/*
|--------------------------------------------------------------------------
| Order sync — idempotency, collision safety, and initial state
|--------------------------------------------------------------------------
| Locks the fixes for the WooCommerce empty-key collision, the status-reset
| on re-sync, and the "new orders must await confirmation" rule.
*/

beforeEach(function () {
    Event::fake([OrderCreated::class]);
    Queue::fake();

    $this->user  = User::factory()->create();
    $this->store = Store::factory()->create(['user_id' => $this->user->id]);

    $this->connection = PlatformConnection::create([
        'store_id' => $this->store->id,
        'platform' => 'woocommerce',
        'status'   => 'active',
    ]);

    $this->service = app(OrderSyncService::class);

    // Mirrors the shape BaseConnector::normalizeOrder() produces.
    $this->normalized = fn (string $platformId, array $overrides = []): array => array_merge([
        'platform_id'    => $platformId,
        'number'         => 'WC-' . $platformId,
        'status'         => 'processing',
        'total'          => 250.0,
        'currency'       => 'MAD',
        'customer_name'  => 'Test Customer',
        'customer_email' => 'c@example.com',
        'customer_phone' => '+212600000000',
        'items'          => [['product_id' => 'X', 'name' => 'Item', 'quantity' => 1, 'unit_price' => 250]],
        'created_at'     => now()->toIso8601String(),
        'platform_data'  => ['id' => $platformId],
    ], $overrides);
});

it('creates a new synced order in the awaiting-confirmation state', function () {
    $order = $this->service->saveOrder(($this->normalized)('7778'), $this->connection);

    expect($order)->not->toBeNull()
        ->and($order->platform_order_id)->toBe('7778')
        ->and($order->status)->toBe(OrderStatus::Pending)
        ->and($order->fulfillment_status)->toBe(FulfillmentStatus::Pending)
        ->and(Order::count())->toBe(1);

    Event::assertDispatched(OrderCreated::class, 1);
    Queue::assertPushed(SendWhatsAppConfirmation::class, 1);
});

it('does not duplicate or reset an order that already advanced past confirmation', function () {
    $order = $this->service->saveOrder(($this->normalized)('7778'), $this->connection);

    // The confirmation desk (or a WhatsApp reply) moved it on — the SaaS owns it now.
    $order->update([
        'status'             => OrderStatus::Confirmed,
        'fulfillment_status' => FulfillmentStatus::InProgress,
    ]);

    // A later poll returns the same order with changed platform data.
    $this->service->saveOrder(($this->normalized)('7778', ['total' => 999.0]), $this->connection);

    $order->refresh();

    expect(Order::count())->toBe(1)
        ->and($order->status)->toBe(OrderStatus::Confirmed)                  // NOT reset to Pending
        ->and($order->fulfillment_status)->toBe(FulfillmentStatus::InProgress)
        ->and((float) $order->total)->toBe(250.0);                           // volatile data left alone

    // No second confirmation, no duplicate creation event.
    Event::assertDispatched(OrderCreated::class, 1);
    Queue::assertPushed(SendWhatsAppConfirmation::class, 1);
});

it('refreshes volatile platform data while the order is still awaiting confirmation', function () {
    $order = $this->service->saveOrder(($this->normalized)('7778'), $this->connection);

    $this->service->saveOrder(
        ($this->normalized)('7778', ['total' => 300.0, 'customer_name' => 'Renamed']),
        $this->connection,
    );

    $order->refresh();

    expect(Order::count())->toBe(1)
        ->and($order->fulfillment_status)->toBe(FulfillmentStatus::Pending)
        ->and((float) $order->total)->toBe(300.0)
        ->and($order->customer_name)->toBe('Renamed');
});

it('gives distinct external ids distinct rows (no collision)', function () {
    $this->service->saveOrder(($this->normalized)('100'), $this->connection);
    $this->service->saveOrder(($this->normalized)('200'), $this->connection);

    expect(Order::count())->toBe(2)
        ->and(Order::query()->orderBy('platform_order_id')->pluck('platform_order_id')->all())
        ->toBe(['100', '200']);
});

it('skips an order that has no external id instead of colliding onto one row', function () {
    $result = $this->service->saveOrder(($this->normalized)(''), $this->connection);

    expect($result)->toBeNull()
        ->and(Order::count())->toBe(0);

    Event::assertNotDispatched(OrderCreated::class);
    Queue::assertNothingPushed();
});

it('maps a WooCommerce order onto the normalized platform_id key (root cause of the collision)', function () {
    $connector = new WooCommerceConnector($this->connection);

    $ref = new ReflectionMethod($connector, 'parseOrder');
    $ref->setAccessible(true);
    $parsed = $ref->invoke($connector, ['id' => 7778, 'number' => '1042', 'line_items' => [], 'billing' => []]);

    expect($parsed['platform_id'] ?? null)->toBe('7778')
        ->and($parsed['number'] ?? null)->toBe('1042');
});
