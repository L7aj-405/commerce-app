<?php

declare(strict_types=1);

namespace App\Http\Controllers\Dashboard;

use App\Enums\FulfillmentStatus;
use App\Http\Controllers\Controller;
use App\Models\Order;
use App\Models\OrderNotification;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Lightweight polling endpoint for order badges/toasts — no websockets/
 * broadcasting infra in this project (BROADCAST_CONNECTION=log), so the UI
 * polls this every 15-30s instead. Every query is scoped to the acting
 * user's active store AND to the acting user themselves (per-user "seen"
 * state) — never a cross-store or cross-user leak.
 */
class OrderNotificationController extends Controller
{
    public function counts(Request $request): JsonResponse
    {
        $store = $request->user()->getActiveStore();

        if ($store === null) {
            return response()->json([
                'new_orders_count' => 0,
                'confirmation_pending_count' => 0,
                'unread_notifications_count' => 0,
                'latest_notifications' => [],
            ]);
        }

        $user = $request->user();

        $newOrdersCount = OrderNotification::query()
            ->where('store_id', $store->id)
            ->where('user_id', $user->id)
            ->where('type', OrderNotification::TYPE_NEW_ORDER)
            ->unseen()
            ->count();

        // Live count, not a stored notification — permission-gated at read
        // time so a user without orders.confirm never sees a confirmation
        // queue number that isn't theirs to act on.
        $confirmationPendingCount = $user->hasStorePermission($store, 'orders.confirm')
            ? Order::query()->where('store_id', $store->id)->where('fulfillment_status', FulfillmentStatus::Pending)->count()
            : 0;

        $latest = OrderNotification::query()
            ->where('store_id', $store->id)
            ->where('user_id', $user->id)
            ->latest('created_at')
            ->limit(10)
            ->get()
            ->map(fn (OrderNotification $n) => [
                'id' => $n->id,
                'order_id' => $n->order_id,
                'type' => $n->type,
                'source_platform' => $n->source_platform,
                'title' => $n->title,
                'message' => $n->message,
                'seen' => $n->seen_at !== null,
                'created_at' => $n->created_at?->toIso8601String(),
            ]);

        return response()->json([
            'new_orders_count' => $newOrdersCount,
            'confirmation_pending_count' => $confirmationPendingCount,
            'unread_notifications_count' => $newOrdersCount,
            'latest_notifications' => $latest,
        ]);
    }

    /**
     * Marks this user's own unseen order notifications as seen — never
     * global, never another user's. 'order_detail' (with order_id) marks
     * only that order's notification; 'orders_index'/'confirmation_desk'
     * marks every unseen new_order notification, since opening either list
     * page is what the ticket defines as "seen".
     */
    public function markSeen(Request $request): JsonResponse
    {
        $store = $request->user()->getActiveStore();
        abort_if($store === null, 422, 'No active store.');

        $validated = $request->validate([
            'context' => ['required', 'string', 'in:orders_index,confirmation_desk,order_detail'],
            'order_id' => ['nullable', 'string'],
        ]);

        $query = OrderNotification::query()
            ->where('store_id', $store->id)
            ->where('user_id', $request->user()->id)
            ->unseen();

        if ($validated['context'] === 'order_detail' && filled($validated['order_id'] ?? null)) {
            $query->where('order_id', $validated['order_id']);
        }

        $marked = $query->update(['seen_at' => now()]);

        return response()->json(['ok' => true, 'marked_count' => $marked]);
    }
}
