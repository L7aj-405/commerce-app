<?php

declare(strict_types=1);

namespace App\Http\Controllers\Onboarding;

use App\Enums\StoreStatus;
use App\Enums\StoreType;
use App\Http\Controllers\Controller;
use App\Models\Store;
use App\Models\StoreMember;
use App\Services\OrganizationProvisioner;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\Rule;
use Illuminate\Support\Str;
use Inertia\Inertia;
use Inertia\Response;
use Throwable;

class OnboardingController extends Controller
{
    private const BUSINESS_TYPES = [
        ['value' => 'retail',      'label' => 'Retail Store'],
        ['value' => 'restaurant',  'label' => 'Restaurant / Café'],
        ['value' => 'fashion',     'label' => 'Clothing & Fashion'],
        ['value' => 'electronics', 'label' => 'Electronics'],
        ['value' => 'grocery',     'label' => 'Grocery'],
        ['value' => 'other',       'label' => 'Other'],
    ];

    private const COUNTRIES = [
        ['code' => 'US', 'name' => 'United States',   'currency' => 'USD'],
        ['code' => 'CA', 'name' => 'Canada',          'currency' => 'CAD'],
        ['code' => 'GB', 'name' => 'United Kingdom',  'currency' => 'GBP'],
        ['code' => 'FR', 'name' => 'France',          'currency' => 'EUR'],
        ['code' => 'DE', 'name' => 'Germany',         'currency' => 'EUR'],
        ['code' => 'ES', 'name' => 'Spain',           'currency' => 'EUR'],
        ['code' => 'IT', 'name' => 'Italy',           'currency' => 'EUR'],
        ['code' => 'MA', 'name' => 'Morocco',         'currency' => 'MAD'],
        ['code' => 'DZ', 'name' => 'Algeria',         'currency' => 'DZD'],
        ['code' => 'TN', 'name' => 'Tunisia',         'currency' => 'TND'],
        ['code' => 'EG', 'name' => 'Egypt',           'currency' => 'EGP'],
        ['code' => 'SA', 'name' => 'Saudi Arabia',    'currency' => 'SAR'],
        ['code' => 'AE', 'name' => 'UAE',             'currency' => 'AED'],
        ['code' => 'AU', 'name' => 'Australia',       'currency' => 'AUD'],
        ['code' => 'JP', 'name' => 'Japan',           'currency' => 'JPY'],
        ['code' => 'IN', 'name' => 'India',           'currency' => 'INR'],
        ['code' => 'BR', 'name' => 'Brazil',          'currency' => 'BRL'],
        ['code' => 'MX', 'name' => 'Mexico',          'currency' => 'MXN'],
    ];

    private const PLATFORMS = [
        ['value' => 'pos',         'label' => 'In-store / Physical POS'],
        ['value' => 'woocommerce', 'label' => 'WooCommerce / WordPress'],
        ['value' => 'shopify',     'label' => 'Shopify'],
        ['value' => 'youcan',      'label' => 'YouCan Shop'],
        ['value' => 'other',       'label' => 'Other online store'],
    ];

    /**
     * The literal first onboarding question: "How will you use the
     * platform?" A user who already started a flow (owns a merchant or
     * agency organization but hasn't finished) is sent straight back into
     * it rather than asked to choose again.
     */
    public function show(Request $request): RedirectResponse|Response
    {
        $user = $request->user();

        if ($user->onboarding_completed_at !== null) {
            return redirect()->route('dashboard');
        }

        if ($user->organizationsOwned()->where('type', \App\Models\Organization::TYPE_MERCHANT)->exists()) {
            return redirect()->route('onboarding.merchant.show');
        }

        if ($user->organizationsOwned()->where('type', \App\Models\Organization::TYPE_AGENCY)->exists()) {
            return redirect()->route('onboarding.agency.show');
        }

        return Inertia::render('Onboarding/ModeSelect', [
            'accountModes' => [
                ['value' => 'merchant', 'label' => 'I manage my own business'],
                ['value' => 'agency', 'label' => 'I manage businesses for clients'],
            ],
        ]);
    }

    public function complete(Request $request, OrganizationProvisioner $organizations): RedirectResponse
    {
        $validated = $request->validate([
            'account_mode'  => ['nullable', 'in:merchant,agency'],
            'store_name'    => ['required', 'string', 'max:255'],
            'business_type' => [Rule::requiredIf(fn () => $request->input('account_mode', 'merchant') === 'merchant'), 'nullable', 'in:' . implode(',', array_column(self::BUSINESS_TYPES, 'value'))],
            'country'       => ['required', 'string', 'size:2'],
            'currency'      => ['required', 'string', 'size:3'],
            'phone'         => ['nullable', 'string', 'max:50'],
            'platforms'     => ['nullable', 'array'],
            'platforms.*'   => ['string', 'in:' . implode(',', array_column(self::PLATFORMS, 'value'))],
        ]);

        $user = $request->user();

        try {
            DB::transaction(function () use ($user, $validated, $organizations) {
                $accountMode = $validated['account_mode'] ?? 'merchant';
                $organization = $organizations->createOwnedOrganization(
                    $user,
                    $validated['store_name'],
                    $accountMode === 'agency' ? \App\Models\Organization::TYPE_AGENCY : \App\Models\Organization::TYPE_MERCHANT,
                );

                if ($accountMode === 'agency') {
                    $user->update(['onboarding_completed_at' => now()]);
                    return;
                }

                $store = Store::create([
                    'organization_id' => $organization->id,
                    'user_id'       => $user->id,
                    'name'          => $validated['store_name'],
                    'slug'          => Str::slug($validated['store_name']) . '-' . Str::lower(Str::random(4)),
                    'type'          => StoreType::Online->value,
                    'business_type' => $validated['business_type'],
                    'country'       => $validated['country'],
                    'currency'      => $validated['currency'],
                    'phone'         => $validated['phone'] ?? null,
                    'status'        => StoreStatus::Active->value,
                    'settings'      => [
                        'tax_rate'  => 0,
                        'timezone'  => 'UTC',
                        'platforms' => $validated['platforms'] ?? [],
                    ],
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

                $user->update(['onboarding_completed_at' => now()]);

                $request = request();
                $request->session()->put('store_id', $store->id);
            });
        } catch (Throwable $e) {
            Log::error('Onboarding completion failed', [
                'user_id' => $user->id,
                'error'   => $e->getMessage(),
            ]);

            return back()->withErrors(['store_name' => 'Could not create your store. Please try again.']);
        }

        if (($validated['account_mode'] ?? 'merchant') === 'agency') {
            return redirect()->route('agency.clients.index')->with('success', 'Agency workspace created. Add your first client.');
        }

        return redirect()->route('dashboard')->with('success', 'Welcome aboard! Your store is ready.');
    }
}
