<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Store;
use App\Models\User;
use App\Services\SupportAccess;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class SupportSessionController extends Controller
{
    public function store(
        Request $request,
        User $user,
        Store $store,
        SupportAccess $support,
    ): RedirectResponse {
        $admin = $request->user();
        abort_if($admin === null || ! $admin->isSuperAdmin(), 403);

        abort_unless(
            $store->organization_id !== null
            && $user->organizationsOwned()->whereKey($store->organization_id)->exists(),
            404,
        );

        $validated = $request->validate([
            'reason' => ['required', 'string', 'min:5', 'max:500'],
            'duration' => ['nullable', 'integer', Rule::in([15, 30, 60, 120])],
        ]);

        $support->start(
            $admin,
            $store,
            trim($validated['reason']),
            $request,
            (int) ($validated['duration'] ?? 60),
        );

        return redirect('/dashboard')
            ->with('success', "Support mode started for {$store->name}.");
    }

    public function destroy(Request $request, SupportAccess $support): RedirectResponse
    {
        $admin = $request->user();
        abort_if($admin === null || ! $admin->isSuperAdmin(), 403);

        $ended = $support->end($admin);
        $ownerId = $ended?->organization?->owner_user_id;

        if ($ownerId !== null) {
            return redirect()->route('admin.client.show', $ownerId)
                ->with('success', 'Support mode ended.');
        }

        return redirect()->route('admin.dashboard')
            ->with('success', 'Support mode ended.');
    }
}
