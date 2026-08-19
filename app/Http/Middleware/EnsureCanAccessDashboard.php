<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureCanAccessDashboard
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if ($user === null) {
            return redirect()->route('login');
        }

        $store = $user->getActiveStore();

        if ($user->canAccessDashboard($store)) {
            return $next($request);
        }

        // A POS-only role belongs in the terminal, regardless of whatever
        // legacy value happens to be stored in users.role.
        if ($user->canAccessPos($store)) {
            return redirect('/pos')->with('error', 'Your role has POS access only for this store.');
        }

        abort(403, 'You do not have access to the dashboard for this store.');
    }
}
