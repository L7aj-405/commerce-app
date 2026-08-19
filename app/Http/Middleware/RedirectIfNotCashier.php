<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Models\CashierAccount;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class RedirectIfNotCashier
{
    public function handle(Request $request, Closure $next): Response
    {
        if ($request->user() === null) {
            return redirect()->route('login');
        }

        $cashierAccountId = $request->session()->get('pos.cashier_account_id')
            ?? $request->session()->get('cashier_id');
        $storeId          = $request->session()->get('pos.store_id')
            ?? $request->session()->get('store_id');

        if ($cashierAccountId === null || $storeId === null) {
            return redirect()->route('pos.login');
        }

        $cashier = CashierAccount::query()
            ->where('id', $cashierAccountId)
            ->where('store_id', $storeId)
            ->where('user_id', $request->user()->id)
            ->where('status', 'active')
            ->with('store')
            ->first();

        if ($cashier === null || $cashier->isLocked()) {
            $request->session()->forget(['pos.cashier_account_id', 'pos.store_id']);
            return redirect()->route('pos.login')
                ->withErrors(['pin' => 'Your cashier session has ended. Please sign in again.']);
        }

        $request->attributes->set('pos_store', $cashier->store);
        $request->attributes->set('pos_cashier_account', $cashier);

        return $next($request);
    }
}
