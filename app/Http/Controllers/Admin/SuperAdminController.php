<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Organization;
use App\Models\Store;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class SuperAdminController extends Controller
{
    public function dashboard(): Response
    {
        $totalClients   = Organization::query()->distinct()->count('owner_user_id');
        $activeStores   = Store::query()->where('status', 'active')->count();
        $newThisMonth   = Organization::query()
            ->where('created_at', '>=', now()->startOfMonth())
            ->distinct()
            ->count('owner_user_id');

        $recentSignups = User::query()
            ->whereHas('organizationsOwned')
            ->withCount(['organizationStores as owned_stores_count'])
            ->latest()
            ->limit(10)
            ->get(['id', 'name', 'email', 'is_active', 'created_at']);

        return Inertia::render('Admin/Dashboard', [
            'stats' => [
                'total_clients'  => $totalClients,
                'active_stores'  => $activeStores,
                'new_this_month' => $newThisMonth,
            ],
            'recent' => $recentSignups,
        ]);
    }

    public function clients(Request $request): Response
    {
        $filters = [
            'search' => $request->input('search'),
            'status' => $request->input('status'),
        ];

        $clients = User::query()
            ->whereHas('organizationsOwned')
            ->withCount(['organizationStores as owned_stores_count'])
            ->when($request->filled('search'), function ($q) use ($filters) {
                $term = '%' . $filters['search'] . '%';
                $q->where(function ($q) use ($term) {
                    $q->where('name', 'like', $term)->orWhere('email', 'like', $term);
                });
            })
            ->when($request->filled('status'), function ($q) use ($filters) {
                $q->where('is_active', $filters['status'] === 'active');
            })
            ->latest()
            ->paginate(20)
            ->withQueryString();

        return Inertia::render('Admin/Clients', [
            'clients' => $clients,
            'filters' => $filters,
        ]);
    }

    public function showClient(User $user): Response
    {
        abort_unless($user->organizationsOwned()->exists(), 404);

        $user->loadCount(['organizationStores as owned_stores_count']);
        $user->load([
            'organizationStores:id,user_id,organization_id,name,status,country,currency,created_at',
            'organizationsOwned:id,owner_user_id,name,status,created_at',
        ]);

        // Keep the existing React contract while the underlying ownership model
        // moves from User -> Store to User -> Organization -> Store.
        $user->setRelation('owned_stores', $user->organizationStores);
        $user->unsetRelation('organizationStores');

        return Inertia::render('Admin/ClientDetail', [
            'client' => $user,
        ]);
    }

    public function suspendClient(User $user): RedirectResponse
    {
        abort_unless($user->organizationsOwned()->exists(), 404);

        $user->update(['is_active' => false]);
        Organization::query()->where('owner_user_id', $user->id)->update(['status' => 'suspended']);
        Store::query()
            ->whereIn('organization_id', $user->organizationsOwned()->pluck('id'))
            ->update(['status' => 'suspended']);

        return back()->with('success', "Suspended {$user->name}.");
    }

    public function activateClient(User $user): RedirectResponse
    {
        abort_unless($user->organizationsOwned()->exists(), 404);

        $user->update(['is_active' => true]);
        Organization::query()->where('owner_user_id', $user->id)->update(['status' => 'active']);
        Store::query()
            ->whereIn('organization_id', $user->organizationsOwned()->pluck('id'))
            ->update(['status' => 'active']);

        return back()->with('success', "Activated {$user->name}.");
    }
}
