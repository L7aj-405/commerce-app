<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Services\SupportAccess;
use App\Support\TenantContext;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Resolve the active store and its parent organization once per request.
 *
 * Store tenancy remains active for legacy models; organization tenancy is now
 * available to all new domain code without changing existing behaviour.
 * Platform admins only receive a tenant when an explicit SupportSession grants
 * them access to exactly one store.
 */
class ResolveTenant
{
    public function __construct(
        private readonly TenantContext $tenant,
        private readonly SupportAccess $support,
    ) {
    }

    public function handle(Request $request, Closure $next): Response
    {
        $this->tenant->forget();

        $user = $request->user();
        $store = $user?->getActiveStore();

        if ($store !== null) {
            $this->tenant->set($store->id, $store->organization_id);
        }

        try {
            $response = $next($request);

            // Support sessions are audited at request level without storing the
            // request body (which may contain customer/payment/personal data).
            if (
                $user?->isSuperAdmin()
                && ! in_array($request->method(), ['GET', 'HEAD', 'OPTIONS'], true)
                && ($supportSession = $this->support->current($user)) !== null
            ) {
                activity('support')
                    ->causedBy($user)
                    ->performedOn($supportSession->store)
                    ->event('support_action')
                    ->withProperties([
                        'support_session_id' => $supportSession->id,
                        'organization_id' => $supportSession->organization_id,
                        'store_id' => $supportSession->store_id,
                        'method' => $request->method(),
                        'path' => '/' . ltrim($request->path(), '/'),
                        'route' => $request->route()?->getName(),
                        'response_status' => $response->getStatusCode(),
                        'ip_address' => $request->ip(),
                    ])
                    ->log('Platform support action');
            }

            return $response;
        } finally {
            // Never let tenant identity bleed into another request when the app
            // runs under a long-lived worker (Octane, RoadRunner, queue tests).
            $this->tenant->forget();
        }
    }
}
