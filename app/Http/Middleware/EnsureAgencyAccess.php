<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureAgencyAccess
{
    public function handle(Request $request, Closure $next): Response
    {
        abort_unless($request->user()?->managedAgencyOrganizations()->isNotEmpty(), 403, 'Agency workspace access required.');
        return $next($request);
    }
}
