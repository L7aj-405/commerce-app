<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Keeps a pure delivery driver inside their standalone interface.
 *
 * A delivery-only agent has dashboard access at the coarse level (orders.deliver
 * is not pos.access), so without this they could reach the manager dashboard and
 * its metrics. This redirects every dashboard route except the driver's own
 * (dashboard.deliveries.*) back to /dashboard/my-deliveries. Non-drivers pass
 * straight through.
 */
class ConfineDeliveryAgent
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if ($user !== null && $user->isDeliveryOnlyAgent()) {
            $routeName = $request->route()?->getName() ?? '';

            if (! str_starts_with($routeName, 'dashboard.deliveries.')) {
                return redirect()->route('dashboard.deliveries.index');
            }
        }

        return $next($request);
    }
}
