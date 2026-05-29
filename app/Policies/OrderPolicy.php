<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\Order;
use App\Models\User;

class OrderPolicy
{
    public function view(User $user, Order $order): bool
    {
        return $order->store->user_id === $user->id;
    }

    public function update(User $user, Order $order): bool
    {
        return $order->store->user_id === $user->id;
    }
}
