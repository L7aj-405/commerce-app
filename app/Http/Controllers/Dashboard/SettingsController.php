<?php

declare(strict_types=1);

namespace App\Http\Controllers\Dashboard;

use App\Http\Controllers\Controller;
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
}
