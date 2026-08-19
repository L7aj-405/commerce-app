<?php

declare(strict_types=1);

namespace App\Http\Controllers\Dashboard;

use App\Enums\StoreStatus;
use App\Http\Controllers\Controller;
use App\Models\Organization;
use App\Models\OrganizationMember;
use App\Models\Store;
use App\Models\StoreMember;
use App\Models\User;
use App\Support\OnboardingOptions;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

// Edit/Update/Destroy methods appended at end

class StoreController extends Controller
{
    public function index(Request $request): Response
    {
        $user = $request->user();

        $stores = $user->accessibleStores()
            ->filter(fn (Store $store) => $user->hasStorePermission($store, 'stores.manage'))
            ->map(function (Store $store): array {
                $store->loadCount('activeMembers as member_count');

                return [
                    'id' => $store->id,
                    'name' => $store->name,
                    'type' => $store->type,
                    'business_type' => $store->business_type,
                    'country' => $store->country,
                    'currency' => $store->currency,
                    'status' => $store->status,
                    'logo' => $store->logo,
                    'member_count' => $store->member_count,
                ];
            })
            ->sortBy('name')
            ->values();

        $currentStoreId = $user->getActiveStore()?->id;

        return Inertia::render('Dashboard/Stores/Index', [
            'stores'         => $stores,
            'currentStoreId' => $currentStoreId,
        ]);
    }

    /**
     * Add Store is Organization-first: it never invents a workspace. It shows
     * whichever organization is already active (or the one the user owns/
     * manages, if there's no active store yet) and lets them create a
     * store/brand inside it. An agency's own organization never gets a
     * store — the page renders a guidance state instead of the form.
     */
    public function create(Request $request): Response|RedirectResponse
    {
        $organization = $this->resolveActiveOrganization($request->user());

        if ($organization === null) {
            return redirect()->route('onboarding')->with('error', 'Select or create a workspace first.');
        }

        return Inertia::render('Dashboard/Stores/Create', [
            'organization' => [
                'id' => $organization->id,
                'name' => $organization->name,
                'type' => $organization->type,
            ],
            'storeTypes' => OnboardingOptions::STORE_TYPES,
            'industries' => OnboardingOptions::INDUSTRIES,
            'countries' => OnboardingOptions::COUNTRIES,
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'organization_id' => ['nullable', 'string', 'exists:organizations,id'],
            'store_name'      => ['required', 'string', 'max:255'],
            'store_type'      => ['required', Rule::in(OnboardingOptions::storeTypeValues())],
            'business_type'   => ['nullable', Rule::in(OnboardingOptions::industryValues())],
            'country'         => ['required', 'string', 'size:2'],
            'currency'        => ['required', 'string', 'size:3'],
            'phone'           => ['nullable', 'string', 'max:50'],
        ]);

        $user = $request->user();
        $organization = $validated['organization_id'] ?? null
            ? Organization::find($validated['organization_id'])
            : $this->resolveActiveOrganization($user);

        if ($organization === null) {
            return back()->withErrors(['organization_id' => 'Select or create a workspace first.'])->withInput();
        }

        // Organization-level authorization — never users.role. A brand new
        // store has no StoreRole to check against yet, so this is the only
        // meaningful gate: can this user manage the WORKSPACE they're
        // adding a store to.
        abort_unless($user->canManageOrganization($organization), 403, 'You do not manage this organization.');

        if ($organization->isAgency()) {
            return back()
                ->withErrors(['organization_id' => 'Stores belong to client organizations. Add or open a client first.'])
                ->withInput();
        }

        $store = DB::transaction(function () use ($user, $validated, $organization) {
            $store = Store::create([
                'organization_id' => $organization->id,
                'user_id'       => $user->id,
                'name'          => $validated['store_name'],
                'slug'          => Str::slug($validated['store_name']) . '-' . Str::lower(Str::random(4)),
                'type'          => $validated['store_type'],
                'business_type' => $validated['business_type'] ?? null,
                'country'       => $validated['country'],
                'currency'      => $validated['currency'],
                'phone'         => $validated['phone'] ?? null,
                'status'        => StoreStatus::Active->value,
                'settings'      => ['tax_rate' => 0, 'timezone' => 'UTC'],
            ]);

            $store->ensureDefaultRoles();

            StoreMember::create([
                'store_id'      => $store->id,
                'user_id'       => $user->id,
                'role'          => 'store_admin',
                'store_role_id' => $store->adminRole()?->id,
                'is_active'     => true,
                'joined_at'     => now(),
            ]);

            return $store;
        });

        $request->session()->put('store_id', $store->id);

        return redirect()->route('dashboard.stores.index')->with('success', "Store \"{$store->name}\" created.");
    }

    /**
     * The organization a new store should attach to, absent an explicit
     * choice: the active store's organization, else an organization the
     * user owns, else one they own/admin via membership. Never creates
     * anything — returns null when nothing qualifies (OrganizationProvisioner
     * ::forNewStore()'s silent-create fallback is deliberately not used here).
     */
    private function resolveActiveOrganization(User $user): ?Organization
    {
        $activeStoreOrganization = $user->getActiveStore()?->organization;

        if ($activeStoreOrganization !== null) {
            return $activeStoreOrganization;
        }

        $owned = $user->organizationsOwned()->first();

        if ($owned !== null) {
            return $owned;
        }

        return Organization::query()
            ->whereHas('memberships', fn ($q) => $q
                ->where('user_id', $user->id)
                ->where('is_active', true)
                ->whereIn('role', [OrganizationMember::ROLE_OWNER, OrganizationMember::ROLE_ADMIN]))
            ->first();
    }

    public function edit(Request $request, Store $store): Response
    {
        abort_unless($request->user()->hasStorePermission($store, 'stores.manage'), 403);

        return Inertia::render('Dashboard/Stores/Edit', [
            'store' => $store->only(['id', 'name', 'type', 'business_type', 'country', 'currency', 'phone', 'settings']),
            'storeTypes' => OnboardingOptions::STORE_TYPES,
            'industries' => OnboardingOptions::INDUSTRIES,
        ]);
    }

    public function update(Request $request, Store $store): RedirectResponse
    {
        abort_unless($request->user()->hasStorePermission($store, 'stores.manage'), 403);

        $validated = $request->validate([
            'name'          => ['required', 'string', 'max:255'],
            'store_type'    => ['nullable', Rule::in(OnboardingOptions::storeTypeValues())],
            'business_type' => ['nullable', Rule::in(OnboardingOptions::industryValues())],
            'country'       => ['nullable', 'string', 'size:2'],
            'currency'      => ['nullable', 'string', 'size:3'],
            'phone'         => ['nullable', 'string', 'max:50'],
        ]);

        if (array_key_exists('store_type', $validated)) {
            $validated['type'] = $validated['store_type'];
            unset($validated['store_type']);
        }

        $store->update($validated);

        return back()->with('success', 'Store updated.');
    }

    public function destroy(Request $request, Store $store): RedirectResponse
    {
        abort_unless($request->user()->hasStorePermission($store, 'stores.manage'), 403);

        $store->delete();

        return redirect()->route('dashboard.stores.index')->with('success', 'Store deleted.');
    }
}
