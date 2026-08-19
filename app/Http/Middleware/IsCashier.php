<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Models\CashierAccount;
use App\Models\Store;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class IsCashier
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if ($user === null) {
            abort(401, 'Unauthenticated.');
        }

        $store = $this->resolveStore($request, $user->id);

        if ($store === null) {
            abort(403, 'No active store for cashier.');
        }

        $cashier = CashierAccount::query()
            ->where('store_id', $store->id)
            ->where('user_id', $user->id)
            ->where('status', 'active')
            ->first();

        if ($cashier === null) {
            abort(403, 'Not an active cashier for this store.');
        }

        if ($cashier->isLocked()) {
            abort(403, 'Cashier account is locked.');
        }

        $request->attributes->set('pos_store', $store);
        $request->attributes->set('pos_cashier_account', $cashier);

        return $next($request);
    }

    private function resolveStore(Request $request, string $userId): ?Store
    {
        $storeId = $request->input('store_id')
            ?? $request->route('store')
            ?? session('current_store_id');

        if ($storeId !== null) {
            return Store::query()->where('id', $storeId)->where('user_id', $userId)->first();
        }

        return Store::query()->where('user_id', $userId)->first();
    }
}
