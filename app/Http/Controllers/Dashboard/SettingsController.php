<?php

declare(strict_types=1);

namespace App\Http\Controllers\Dashboard;

use App\Http\Controllers\Controller;
use App\Support\BrandAppearance;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class SettingsController extends Controller
{
    public function index(Request $request): Response
    {
        $store = $request->user()->getActiveStore();

        return Inertia::render('Dashboard/Settings/Index', [
            'store' => $store?->only(['id', 'name', 'country', 'currency', 'phone', 'business_type', 'settings']),
            'branding' => BrandAppearance::resolve($store),
        ]);
    }

    public function update(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'name'          => ['required', 'string', 'max:255'],
            'country'       => ['nullable', 'string', 'size:2'],
            'currency'      => ['nullable', 'string', 'size:3'],
            'phone'         => ['nullable', 'string', 'max:50'],
            'business_type' => ['nullable', 'in:retail,restaurant,fashion,electronics,grocery,other'],
            'tax_rate'      => ['nullable', 'numeric', 'min:0', 'max:1'],
        ]);

        $store = $request->user()->getActiveStore();
        abort_if($store === null, 422, 'No active store.');

        $settings = array_merge($store->settings ?? [], ['tax_rate' => (float) ($validated['tax_rate'] ?? 0)]);

        $store->update([
            'name'          => $validated['name'],
            'country'       => $validated['country']  ?? $store->country,
            'currency'      => $validated['currency'] ?? $store->currency,
            'phone'         => $validated['phone']    ?? $store->phone,
            'business_type' => $validated['business_type'] ?? $store->business_type,
            'settings'      => $settings,
        ]);

        return back()->with('success', 'Settings saved.');
    }

    /**
     * Store-level brand appearance (primary/accent color, font, radius) —
     * gated by the same `settings.manage` permission as the rest of Store
     * Settings (Manager's default role does not hold it, so this is already
     * effectively owner/admin-only). Persisted alongside `tax_rate` inside
     * the existing `stores.settings` JSON column, never a separate table.
     */
    public function updateBranding(Request $request): RedirectResponse
    {
        $store = $request->user()->getActiveStore();
        abort_if($store === null, 422, 'No active store.');

        $validated = $request->validate(BrandAppearance::validationRules());
        $settings = $store->settings ?? [];

        if ($validated['reset'] ?? false) {
            unset($settings['branding']);
        } else {
            $settings['branding'] = [
                'primary' => $validated['primary'] ?? null,
                'accent'  => $validated['accent'] ?? null,
                'font'    => $validated['font'] ?? BrandAppearance::DEFAULT_FONT,
                'radius'  => $validated['radius'] ?? BrandAppearance::DEFAULT_RADIUS,
            ];
        }

        $store->update(['settings' => $settings]);

        return back()->with('success', 'Brand appearance updated.');
    }
}
