<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Illuminate\Http\Request;
use Inertia\Middleware;

class HandleInertiaRequests extends Middleware
{
    protected $rootView = 'app';

    public function version(Request $request): ?string
    {
        return parent::version($request);
    }

    public function share(Request $request): array
    {
        return array_merge(parent::share($request), [
            'auth' => [
                'user' => $request->user(),
                'stores' => fn () => $request->user()
                    ? $request->user()->accessibleStores()
                        ->map(fn ($store) => [
                            'id' => $store->id,
                            'name' => $store->name,
                            'organization' => $store->organization
                                ? ['id' => $store->organization->id, 'name' => $store->organization->name, 'type' => $store->organization->type]
                                : null,
                        ])
                        ->sortBy('name')
                        ->values()
                    : [],
                'activeStore' => fn () => $request->user()?->getActiveStore()?->only(['id', 'name']),
                'permissions' => fn () => $request->user()
                    ? $request->user()->permissionsForStore($request->user()->getActiveStore())
                    : [],
                'agency' => fn () => ($agency = $request->user()?->managedAgencyOrganizations()->first())
                    ? ['id' => $agency->id, 'name' => $agency->name]
                    : null,
                // The organization behind the CURRENTLY ACTIVE store — not the
                // viewer's own agency (see 'agency' above). This is what lets the
                // UI show "you're viewing Client X" when an agency operator has
                // opened a client's store, since the active store's organization
                // is the client org in that case. Minimal fields only — never the
                // org's settings/metadata JSON.
                'organization' => fn () => ($organization = $request->user()?->getActiveStore()?->organization)
                    ? [
                        'id' => $organization->id,
                        'name' => $organization->name,
                        'type' => $organization->type,
                        'store' => ($store = $request->user()->getActiveStore())
                            ? ['id' => $store->id, 'name' => $store->name]
                            : null,
                        'supportMode' => app(\App\Services\SupportAccess::class)->current($request->user()) !== null,
                    ]
                    : null,
                'access' => fn () => $request->user()
                    ? $request->user()->accessProfileForStore($request->user()->getActiveStore())
                    : [
                        'roleName' => null,
                        'roleSlug' => null,
                        'canDashboard' => false,
                        'canPos' => false,
                        'canManageOrganization' => false,
                    ],
                'support' => fn () => app(\App\Services\SupportAccess::class)->profile($request->user()),
            ],
            'flash' => [
                'success' => fn () => $request->session()->get('success'),
                'error' => fn () => $request->session()->get('error'),
                'warning' => fn () => $request->session()->get('warning'),
            ],
        ]);
    }
}
