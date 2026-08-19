<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\Order;
use App\Models\User;

/**
 * Tenancy + permission rules for orders. Every check first confirms the order
 * belongs to the user's active store (multi-tenancy), then checks the granular
 * permission — so an owner keeps full access and a member is bound to their role.
 */
class OrderPolicy
{
    private function inActiveStore(User $user, Order $order): bool
    {
        return $order->store_id === $user->getActiveStore()?->id;
    }

    public function viewAny(User $user): bool
    {
        return $user->hasStorePermission($user->getActiveStore(), 'orders.view');
    }

    public function view(User $user, Order $order): bool
    {
        return $this->inActiveStore($user, $order)
            && $user->hasStorePermission($user->getActiveStore(), 'orders.view');
    }

    public function update(User $user, Order $order): bool
    {
        return $this->inActiveStore($user, $order)
            && $user->hasStorePermission($user->getActiveStore(), 'orders.manage');
    }

    /** Generating an invoice from this order. */
    public function invoice(User $user, Order $order): bool
    {
        return $this->inActiveStore($user, $order)
            && $user->hasStorePermission($user->getActiveStore(), 'invoices.issue');
    }
}
