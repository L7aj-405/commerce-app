<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class RedirectIfCashier
{
    public function handle(Request $request, Closure $next): Response
    {
        $hasCashier = $request->session()->has('pos.cashier_account_id')
            || $request->session()->has('cashier_id');
        $hasStore   = $request->session()->has('pos.store_id')
            || $request->session()->has('store_id');

        if ($request->user() !== null && $hasCashier && $hasStore) {
            return redirect()->route('pos.dashboard');
        }

        return $next($request);
    }
}
