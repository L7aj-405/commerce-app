<?php

declare(strict_types=1);

namespace App\Listeners;

use App\Events\OrderCreated;
use App\Models\OrderNotification;
use App\Models\Store;
use App\Models\User;
use Illuminate\Contracts\Queue\ShouldQueue;

/**
 * Fires once per genuinely NEW order (OrderCreated is only dispatched from
 * OrderSyncService::saveOrder()'s create branch — never on a re-sync of an
 * already-imported order), regardless of whether that order arrived via a
 * webhook or a manual/queued/scheduled sync — one shared listener for every
 * import path.
 *
 * Recipients: every active member (+ the store owner) who can see orders at
 * all (`orders.view`) — covers confirmation agents, operations users, and
 * the merchant owner without hand-picking one narrower permission and
 * missing a role the ticket named. One OrderNotification row per recipient,
 * `afterCommit` so a notification is never created for an order whose
 * transaction later rolled back.
 */
class CreateNewOrderNotifications implements ShouldQueue
{
    public bool $afterCommit = true;

    public function handle(OrderCreated $event): void
    {
        $order = $event->order;
        $order->loadMissing('store');
        $store = $order->store;

        if ($store === null) {
            return;
        }

        $platformLabel = match ($order->source_platform) {
            'shopify' => 'Shopify',
            'woocommerce' => 'WooCommerce',
            'youcan' => 'YouCan',
            default => ucfirst((string) ($order->source_platform ?? 'the platform')),
        };

        $title = "New order received from {$platformLabel}";
        $message = trim(($order->order_number ? "#{$order->order_number}" : '') . ' ' . ($order->customer_name ?? ''));

        foreach ($this->recipients($store) as $user) {
            OrderNotification::firstOrCreate(
                ['user_id' => $user->id, 'order_id' => $order->id, 'type' => OrderNotification::TYPE_NEW_ORDER],
                [
                    'store_id' => $store->id,
                    'organization_id' => $store->organization_id,
                    'source_platform' => $order->source_platform,
                    'title' => $title,
                    'message' => $message !== '' ? $message : null,
                ],
            );
        }
    }

    /** @return \Illuminate\Support\Collection<int, User> */
    private function recipients(Store $store): \Illuminate\Support\Collection
    {
        return $store->members()
            ->with('user:id,name')
            ->where('is_active', true)
            ->get()
            ->pluck('user')
            ->filter()
            ->push($store->owner)
            ->filter()
            ->unique('id')
            ->filter(fn (User $u) => $u->hasStorePermission($store, 'orders.view'));
    }
}
