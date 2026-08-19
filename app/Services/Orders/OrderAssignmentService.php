<?php

declare(strict_types=1);

namespace App\Services\Orders;

use App\Enums\FulfillmentStatus;
use App\Models\Order;
use App\Models\PosOrder;
use App\Models\Store;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Who is working which order.
 *
 * The point of claiming is that two confirmation agents never ring the same
 * customer, so every claim is a locked read-then-write: checking "is it free?"
 * outside a lock is exactly the race this is meant to prevent.
 */
class OrderAssignmentService
{
    /**
     * Claim the longest-waiting unassigned order in a phase. Null when the
     * queue is empty.
     */
    public function takeNext(Store $store, User $actor, string $phase): Order|PosOrder|null
    {
        $statuses = array_map(
            fn (FulfillmentStatus $s) => $s->value,
            array_filter(FulfillmentStatus::cases(), fn ($s) => $s->phase() === $phase),
        );

        return DB::transaction(function () use ($store, $actor, $statuses) {
            foreach ([Order::class, PosOrder::class] as $model) {
                $order = $model::query()
                    ->where('store_id', $store->id)
                    ->whereIn('fulfillment_status', $statuses)
                    ->where('fulfillment_status', '!=', FulfillmentStatus::WaitingForStock->value)
                    ->whereNull('assigned_to')
                    ->oldest()
                    ->lockForUpdate()
                    ->first();

                if ($order !== null) {
                    $order->update(['assigned_to' => $actor->id, 'assigned_at' => now()]);

                    return $order;
                }
            }

            return null;
        });
    }

    /**
     * Claim one specific order.
     *
     * @throws ValidationException when someone else already holds it
     */
    public function claim(Order|PosOrder $order, User $actor): Order|PosOrder
    {
        return DB::transaction(function () use ($order, $actor) {
            $fresh = $order->newQuery()->lockForUpdate()->findOrFail($order->getKey());

            if (($fresh->fulfillment_status ?? null) === FulfillmentStatus::WaitingForStock) {
                throw ValidationException::withMessages([
                    'assigned_to' => 'This order is waiting for stock before the warehouse can claim it.',
                ]);
            }

            if ($fresh->assigned_to !== null && $fresh->assigned_to !== $actor->id) {
                throw ValidationException::withMessages([
                    'assigned_to' => 'Another agent picked this order up first.',
                ]);
            }

            $fresh->update(['assigned_to' => $actor->id, 'assigned_at' => now()]);

            return $fresh;
        });
    }

    /** Put an order back in the shared queue. */
    public function release(Order|PosOrder $order): Order|PosOrder
    {
        $order->update(['assigned_to' => null, 'assigned_at' => null]);

        return $order->refresh();
    }

    /**
     * Everyone who can work this phase, with their current load — the data
     * behind the "active agents / queue distribution" widget.
     *
     * @return array<int, array{id:string,name:string,initials:string,assigned:int,is_you:bool}>
     */
    public function workload(Store $store, string $permission, User $viewer, string $phase): array
    {
        $statuses = array_map(
            fn (FulfillmentStatus $s) => $s->value,
            array_filter(FulfillmentStatus::cases(), fn ($s) => $s->phase() === $phase),
        );

        $candidates = $store->members()
            ->with('user:id,name')
            ->where('is_active', true)
            ->get()
            ->pluck('user')
            ->filter()
            ->push($store->owner)                 // the owner works every queue
            ->filter()
            ->unique('id')
            ->filter(fn (User $u) => $u->hasStorePermission($store, $permission));

        $loads = collect([Order::class, PosOrder::class])
            ->flatMap(fn (string $model) => $model::query()
                ->where('store_id', $store->id)
                ->whereIn('fulfillment_status', $statuses)
                ->whereNotNull('assigned_to')
                ->select('assigned_to')
                ->get()
                ->pluck('assigned_to'))
            ->countBy()
            ->all();

        return $candidates->map(fn (User $u) => [
            'id'       => $u->id,
            'name'     => $u->name,
            'initials' => self::initials($u->name),
            'assigned' => $loads[$u->id] ?? 0,
            'is_you'   => $u->id === $viewer->id,
        ])->sortByDesc('assigned')->values()->all();
    }

    private static function initials(string $name): string
    {
        $parts = preg_split('/\s+/', trim($name)) ?: [];

        return strtoupper(mb_substr($parts[0] ?? '?', 0, 1) . mb_substr($parts[1] ?? '', 0, 1));
    }
}
