<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureCanAccessPos
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if ($user === null) {
            return redirect()->route('login');
        }

        $store = $user->getActiveStore();

        if (! $user->canAccessPos($store)) {
            abort(403, 'You do not have POS access for this store.');
        }

        return $next($request);
    }
}
