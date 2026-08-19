<?php

declare(strict_types=1);

namespace App\Http\Controllers\Auth;

use App\Enums\UserStatus;
use App\Http\Controllers\Controller;
use App\Models\StoreInvitation;
use App\Models\StoreMember;
use App\Models\User;
use App\Services\OrganizationProvisioner;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Inertia\Inertia;
use Inertia\Response;

class InvitationController extends Controller
{
    public function show(string $token): Response
    {
        $invitation = StoreInvitation::query()
            ->where('token', $token)
            ->with([
                'store:id,name,organization_id',
                'storeRole:id,name,slug,permissions',
            ])
            ->first();

        if ($invitation === null || $invitation->isExpired()) {
            return Inertia::render('Auth/InvitationInvalid', [
                'reason' => $invitation === null ? 'not_found' : 'expired',
            ]);
        }

        $existingUser = User::query()->where('email', $invitation->email)->first();

        return Inertia::render('Auth/AcceptInvitation', [
            'invitation' => [
                'token'       => $invitation->token,
                'email'       => $invitation->email,
                'role_name'   => $invitation->storeRole?->name ?? 'Team member',
                'permissions' => $invitation->storeRole?->effectivePermissions() ?? [],
            ],
            'store'        => $invitation->store?->only(['id', 'name']),
            'existingUser' => $existingUser !== null,
        ]);
    }

    public function accept(
        Request $request,
        string $token,
        OrganizationProvisioner $organizations,
    ): RedirectResponse {
        $invitation = StoreInvitation::query()
            ->where('token', $token)
            ->with(['store.organization', 'storeRole'])
            ->first();

        if ($invitation === null || $invitation->isExpired()) {
            return redirect()->route('login')
                ->withErrors(['invitation' => 'This invitation is no longer valid.']);
        }

        $user = User::query()->where('email', $invitation->email)->first();

        if ($user === null) {
            $validated = $request->validate([
                'name'     => ['required', 'string', 'max:255'],
                'password' => ['required', 'string', 'min:8', 'confirmed'],
            ]);

            $user = DB::transaction(function () use ($invitation, $validated) {
                return User::create([
                    'name'                    => $validated['name'],
                    'email'                   => $invitation->email,
                    'password'                => Hash::make($validated['password']),
                    'status'                  => UserStatus::Active->value,
                    // Legacy compatibility only. StoreRole is authoritative.
                    'role'                    => $invitation->role,
                    'is_active'               => true,
                    'onboarding_completed_at' => now(),
                ]);
            });
        }

        DB::transaction(function () use ($invitation, $user, $organizations) {
            StoreMember::updateOrCreate(
                ['store_id' => $invitation->store_id, 'user_id' => $user->id],
                [
                    'role'          => $invitation->role, // legacy compatibility only
                    'store_role_id' => $invitation->store_role_id,
                    'is_active'     => true,
                    'joined_at'     => now(),
                ],
            );

            if ($invitation->store?->organization !== null) {
                $organizations->ensureMember($invitation->store->organization, $user);
            }

            $invitation->update([
                'status'      => 'accepted',
                'accepted_at' => now(),
            ]);
        });

        Auth::login($user);
        $request->session()->regenerate();
        $request->session()->put('store_id', $invitation->store_id);

        $store = $invitation->store;

        if ($user->isDeliveryOnlyAgent($store)) {
            $destination = '/dashboard/my-deliveries';
        } elseif ($user->canAccessDashboard($store)) {
            $destination = '/dashboard';
        } elseif ($user->canAccessPos($store)) {
            $destination = '/pos';
        } else {
            $destination = '/dashboard';
        }

        return redirect($destination)
            ->with('success', "Welcome to {$store?->name}!");
    }
}
