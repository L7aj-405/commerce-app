<?php

declare(strict_types=1);

namespace App\Http\Controllers\Dashboard;

use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class StoreSwitchController extends Controller
{
    public function switch(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'store_id' => ['required', 'string', 'exists:stores,id'],
        ]);

        // Any store the user can act in — owned OR joined as a team member.
        $store = $request->user()->accessibleStores()->firstWhere('id', $validated['store_id']);

        abort_if($store === null, 403, 'Store not found or not accessible to you.');

        $request->session()->put('store_id', $store->id);

        return back()->with('success', "Switched to {$store->name}");
    }
}
