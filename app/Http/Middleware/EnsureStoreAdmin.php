<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureStoreAdmin
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if ($user === null) {
            return redirect()->route('login');
        }

        $store = $user->getActiveStore();

        if (! $user->isStoreAdmin($store)) {
            abort(403, 'Only store administrators can access this area.');
        }

        return $next($request);
    }
}
