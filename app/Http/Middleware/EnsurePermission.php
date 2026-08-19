<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Granular permission gate for the active store.
 *
 * Usage: ->middleware('perm:products.manage')
 *
 * Store/workspace owners bypass checks. A platform super admin only bypasses
 * checks while an explicit Support Session is scoped to the active Store.
 */
class EnsurePermission
{
    public function handle(Request $request, Closure $next, string $permission): Response
    {
        $user = $request->user();

        if ($user === null) {
            return redirect()->route('login');
        }

        $store = $user->getActiveStore();

        if (! $user->hasStorePermission($store, $permission)) {
            abort(403, 'You do not have permission to access this area.');
        }

        return $next($request);
    }
}
