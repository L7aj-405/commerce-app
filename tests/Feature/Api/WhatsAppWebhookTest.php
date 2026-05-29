<?php

declare(strict_types=1);

use App\Enums\OrderStatus;
use App\Models\Order;
use App\Models\Store;
use App\Models\User;

// ── Verify endpoint ───────────────────────────────────────────────────────────

it('returns challenge when verify token matches a store credential', function (): void {
    $user  = User::factory()->create();
    $store = Store::factory()->create(['user_id' => $user->id]);
    $store->getOrCreateCredentials()->update(['whatsapp_webhook_verify_token' => 'my-secret-token']);

    $response = $this->get('/api/webhooks/whatsapp?hub.mode=subscribe&hub.challenge=test_challenge_123&hub.verify_token=my-secret-token');

    $response->assertOk()->assertSee('test_challenge_123');
});

it('returns 403 when verify token does not match any store', function (): void {
    $this->get('/api/webhooks/whatsapp?hub.mode=subscribe&hub.challenge=abc&hub.verify_token=wrong-token')
        ->assertForbidden();
});

it('returns 403 when hub mode is not subscribe', function (): void {
    $user  = User::factory()->create();
    $store = Store::factory()->create(['user_id' => $user->id]);
    $store->getOrCreateCredentials()->update(['whatsapp_webhook_verify_token' => 'my-secret-token']);

    $this->get('/api/webhooks/whatsapp?hub.mode=unsubscribe&hub.challenge=abc&hub.verify_token=my-secret-token')
        ->assertForbidden();
});

// ── Message webhook ───────────────────────────────────────────────────────────

it('marks order as confirmed when customer replies YES', function (): void {
    $order = Order::factory()->pending()->create(['customer_phone' => '+212612345678']);

    $this->postJson('/api/webhooks/whatsapp', metaPayload('212612345678', 'YES'))
        ->assertOk();

    expect($order->fresh()->status)->toBe(OrderStatus::Confirmed)
        ->and($order->fresh()->whatsapp_confirmed_at)->not->toBeNull();
});

it('marks order as confirmed when customer replies 1', function (): void {
    $order = Order::factory()->pending()->create(['customer_phone' => '+212612345678']);

    $this->postJson('/api/webhooks/whatsapp', metaPayload('212612345678', '1'))
        ->assertOk();

    expect($order->fresh()->status)->toBe(OrderStatus::Confirmed);
});

it('marks order as cancelled when customer replies NO', function (): void {
    $order = Order::factory()->pending()->create(['customer_phone' => '+212612345678']);

    $this->postJson('/api/webhooks/whatsapp', metaPayload('212612345678', 'NO'))
        ->assertOk();

    expect($order->fresh()->status)->toBe(OrderStatus::Cancelled)
        ->and($order->fresh()->whatsapp_cancelled_at)->not->toBeNull();
});

it('marks order as cancelled when customer replies 0', function (): void {
    $order = Order::factory()->pending()->create(['customer_phone' => '+212612345678']);

    $this->postJson('/api/webhooks/whatsapp', metaPayload('212612345678', '0'))
        ->assertOk();

    expect($order->fresh()->status)->toBe(OrderStatus::Cancelled);
});

it('matches phone number with country code prefix difference', function (): void {
    // Stored as local format, Meta sends international format
    $order = Order::factory()->pending()->create(['customer_phone' => '0612345678']);

    $this->postJson('/api/webhooks/whatsapp', metaPayload('212612345678', 'yes'))
        ->assertOk();

    expect($order->fresh()->status)->toBe(OrderStatus::Confirmed);
});

it('returns 200 but does nothing when phone number has no matching pending order', function (): void {
    $order = Order::factory()->pending()->create(['customer_phone' => '+212699999999']);

    $this->postJson('/api/webhooks/whatsapp', metaPayload('212600000000', 'YES'))
        ->assertOk()
        ->assertJson(['status' => 'ok']);

    expect($order->fresh()->status)->toBe(OrderStatus::Pending);
});

it('returns 200 and ignores non-message events like status updates', function (): void {
    $payload = [
        'object' => 'whatsapp_business_account',
        'entry'  => [[
            'changes' => [[
                'value' => [
                    'statuses' => [[
                        'id'        => 'wamid.abc123',
                        'status'    => 'delivered',
                        'timestamp' => '1234567890',
                        'recipient_id' => '212612345678',
                    ]],
                ],
            ]],
        ]],
    ];

    $this->postJson('/api/webhooks/whatsapp', $payload)
        ->assertOk()
        ->assertJson(['status' => 'ok']);
});

it('returns ignored for unknown object type', function (): void {
    $this->postJson('/api/webhooks/whatsapp', ['object' => 'instagram'])
        ->assertOk()
        ->assertJson(['status' => 'ignored']);
});

// ── Helper ────────────────────────────────────────────────────────────────────

/**
 * Build a minimal Meta WhatsApp webhook payload.
 *
 * @return array<string, mixed>
 */
function metaPayload(string $from, string $body): array
{
    return [
        'object' => 'whatsapp_business_account',
        'entry'  => [[
            'changes' => [[
                'value' => [
                    'messages' => [[
                        'from'      => $from,
                        'text'      => ['body' => $body],
                        'timestamp' => (string) time(),
                    ]],
                ],
            ]],
        ]],
    ];
}
