<?php

declare(strict_types=1);

namespace App\Http\Controllers\Dashboard;

use App\Enums\UserStatus;
use App\Http\Controllers\Controller;
use App\Mail\InvitationMail;
use App\Models\CashierAccount;
use App\Models\Store;
use App\Models\StoreInvitation;
use App\Models\StoreMember;
use App\Models\StoreRole;
use App\Models\User;
use App\Services\OrganizationProvisioner;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;
use Throwable;

class TeamController extends Controller
{
    public function index(Request $request): Response
    {
        $store = $request->user()->getActiveStore();

        abort_if($store === null, 422, 'No active store.');

        $store->ensureDefaultRoles();

        $members = StoreMember::query()
            ->where('store_id', $store->id)
            ->where('is_active', true)
            ->with([
                'user:id,name,email',
                'user.organizationMemberships:id,organization_id,user_id,role,is_active',
                'storeRole:id,name',
            ])
            ->orderBy('joined_at')
            ->get()
            ->map(fn (StoreMember $member) => [
                'id'        => $member->id,
                'role'      => $member->role, // legacy display fallback only
                'role_name' => $this->effectiveRoleName($store, $member),
                'is_owner'  => $this->isWorkspaceOwner($store, $member->user_id),
                'joined_at' => $member->joined_at,
                'user'      => $member->user,
            ]);

        $invitations = StoreInvitation::query()
            ->where('store_id', $store->id)
            ->whereIn('status', ['pending'])
            ->where('expires_at', '>', now())
            ->with('storeRole:id,name')
            ->orderByDesc('created_at')
            ->get()
            ->map(fn (StoreInvitation $invitation) => [
                'id'         => $invitation->id,
                'email'      => $invitation->email,
                'role_name'  => $invitation->storeRole?->name ?? ucfirst((string) $invitation->role),
                'expires_at' => $invitation->expires_at,
            ]);

        return Inertia::render('Dashboard/Team', [
            'store'       => ['id' => $store->id, 'name' => $store->name],
            'members'     => $members,
            'invitations' => $invitations,
        ]);
    }

    public function invite(Request $request): Response
    {
        $store = $request->user()->getActiveStore();
        abort_if($store === null, 422, 'No active store.');

        $store->ensureDefaultRoles();

        return Inertia::render('Dashboard/InviteMember', [
            'store' => $store->only(['id', 'name']),
            'roles' => $this->availableRoles($store),
        ]);
    }

    /** Show the form to create + add a brand-new user directly to the team. */
    public function create(Request $request): Response
    {
        $store = $request->user()->getActiveStore();
        abort_if($store === null, 422, 'No active store.');

        $store->ensureDefaultRoles();

        return Inertia::render('Dashboard/AddMember', [
            'store' => $store->only(['id', 'name']),
            'roles' => $this->availableRoles($store),
        ]);
    }

    /** Create the user account (or attach an existing one) and add them to the store. */
    public function storeMember(Request $request, OrganizationProvisioner $organizations): RedirectResponse
    {
        $store = $request->user()->getActiveStore();
        abort_if($store === null, 422, 'No active store.');

        $validated = $request->validate([
            'name'          => ['required', 'string', 'max:255'],
            'email'         => ['required', 'email', 'max:255'],
            'password'      => ['nullable', 'string', 'min:8', 'confirmed'],
            'store_role_id' => [
                'required', 'string',
                Rule::exists('store_roles', 'id')->where('store_id', $store->id),
            ],
        ]);

        $role = StoreRole::query()
            ->where('store_id', $store->id)
            ->findOrFail($validated['store_role_id']);

        // Legacy compatibility value only. StoreRole permissions are the source of truth.
        $coarseRole = $role->grantsDashboardAccess() ? 'manager' : 'cashier';

        $existing = User::where('email', $validated['email'])->first();

        if ($existing !== null) {
            $alreadyMember = StoreMember::query()
                ->where('store_id', $store->id)
                ->where('user_id', $existing->id)
                ->where('is_active', true)
                ->exists();

            if ($alreadyMember) {
                return back()->withErrors(['email' => 'This person is already on your team.']);
            }

            $user = $existing;
        } else {
            if (empty($validated['password'])) {
                return back()->withErrors(['password' => 'Set a password to create this new user.'])->onlyInput('name', 'email');
            }

            $user = User::create([
                'name'                    => $validated['name'],
                'email'                   => $validated['email'],
                'password'                => Hash::make($validated['password']),
                'status'                  => UserStatus::Active->value,
                'role'                    => $coarseRole,
                'is_active'               => true,
                // Skip onboarding — this user joins an existing store, not their own.
                'onboarding_completed_at' => now(),
            ]);
        }

        StoreMember::updateOrCreate(
            ['store_id' => $store->id, 'user_id' => $user->id],
            [
                'role'          => $coarseRole,
                'store_role_id' => $role->id,
                'is_active'     => true,
                'joined_at'     => now(),
            ],
        );

        // Store membership and organization membership must stay in sync. The
        // organization membership is the workspace boundary; StoreRole remains
        // the granular operational permission source.
        if ($store->organization !== null) {
            $organizations->ensureMember($store->organization, $user);
        }

        $message = $existing !== null
            ? "{$user->name} was added to your team."
            : "{$user->name}'s account was created and added to your team.";

        return redirect()->route('dashboard.team.index')->with('success', $message);
    }

    /**
     * Roles a store admin can assign, shaped for the member forms.
     *
     * @return \Illuminate\Support\Collection<int, array{id: string, name: string, description: ?string, pos_only: bool}>
     */
    private function availableRoles(Store $store): \Illuminate\Support\Collection
    {
        return $store->roles()
            ->orderByDesc('is_system')
            ->orderBy('name')
            ->get()
            ->map(fn (StoreRole $role) => [
                'id'          => $role->id,
                'name'        => $role->name,
                'description' => $role->description,
                'pos_only'    => ! $role->grantsDashboardAccess(),
                'pos_access'  => $role->hasPermission('pos.access'),
            ]);
    }

    /** Edit an existing team member — role, status, name, and cashier PIN/POS settings. */
    public function editMember(Request $request, StoreMember $member): Response
    {
        $store = $request->user()->getActiveStore();
        abort_if($store === null || $member->store_id !== $store->id, 403);

        $member->load('user:id,name,email');

        $cashier = CashierAccount::query()
            ->where('store_id', $store->id)
            ->where('user_id', $member->user_id)
            ->first();

        return Inertia::render('Dashboard/EditMember', [
            'store'  => $store->only(['id', 'name']),
            'roles'  => $this->availableRoles($store),
            'member' => [
                'id'            => $member->id,
                'store_role_id' => $member->store_role_id,
                'is_active'     => $member->is_active,
                'is_owner'      => $this->isWorkspaceOwner($store, $member->user_id),
                'is_self'       => $member->user_id === $request->user()->id,
                'user'          => [
                    'id'    => $member->user->id,
                    'name'  => $member->user->name,
                    'email' => $member->user->email,
                ],
            ],
            'cashier' => [
                'has_pin'              => $cashier !== null,
                'status'               => $cashier?->status ?? 'active',
                'can_give_discounts'   => (bool) ($cashier?->can_give_discounts ?? false),
                'max_discount_percent' => (float) ($cashier?->max_discount_percent ?? 0),
                'daily_limit'          => $cashier?->daily_limit,
                'locked'               => $cashier?->isLocked() ?? false,
            ],
        ]);
    }

    /** Persist edits to a team member and their cashier account. */
    public function updateMember(Request $request, StoreMember $member): RedirectResponse
    {
        $store = $request->user()->getActiveStore();
        abort_if($store === null || $member->store_id !== $store->id, 403);

        $isOwner = $this->isWorkspaceOwner($store, $member->user_id);

        $validated = $request->validate([
            'name'                 => ['required', 'string', 'max:255'],
            'store_role_id'        => [
                'required', 'string',
                Rule::exists('store_roles', 'id')->where('store_id', $store->id),
            ],
            'is_active'            => ['boolean'],
            'pin_code'             => ['nullable', 'digits:4'],
            'cashier_status'       => ['nullable', 'in:active,inactive,suspended'],
            'can_give_discounts'   => ['boolean'],
            'max_discount_percent' => ['nullable', 'numeric', 'min:0', 'max:100'],
            'daily_limit'          => ['nullable', 'numeric', 'min:0'],
        ]);

        $role = StoreRole::query()->where('store_id', $store->id)->findOrFail($validated['store_role_id']);
        $member->load('user');

        $member->user->update(['name' => $validated['name']]);

        // The owner's own role/status are never demoted through this screen.
        if (! $isOwner) {
            $coarseRole = $role->grantsDashboardAccess() ? 'manager' : 'cashier';

            $member->update([
                'store_role_id' => $role->id,
                'role'          => $coarseRole,
                'is_active'     => $validated['is_active'] ?? true,
            ]);

            // Do NOT mutate users.role here. The same account may hold different
            // StoreRoles in different stores; users.role is legacy/global metadata.
        }

        $this->syncCashierAccount($store, $member, $role, $validated);

        return redirect()->route('dashboard.team.index')->with('success', "{$member->user->name} was updated.");
    }

    /**
     * Create / update / deactivate the member's CashierAccount based on the
     * selected role and the submitted PIN + POS settings.
     *
     * @param  array<string, mixed>  $data
     */
    private function syncCashierAccount(Store $store, StoreMember $member, StoreRole $role, array $data): void
    {
        $cashier = CashierAccount::query()
            ->where('store_id', $store->id)
            ->where('user_id', $member->user_id)
            ->first();

        // Role no longer grants POS access → disable the terminal login if one exists.
        if (! $role->hasPermission('pos.access')) {
            $cashier?->update(['status' => 'inactive']);

            return;
        }

        $posFields = [
            'status'               => $data['cashier_status'] ?? ($cashier?->status ?? 'active'),
            'can_give_discounts'   => $data['can_give_discounts'] ?? false,
            'max_discount_percent' => $data['max_discount_percent'] ?? 0,
            'daily_limit'          => $data['daily_limit'] ?? null,
        ];

        // A new PIN was supplied: set it (the 'hashed' cast bcrypts) and clear any lockout.
        if (! empty($data['pin_code'])) {
            CashierAccount::updateOrCreate(
                ['store_id' => $store->id, 'user_id' => $member->user_id],
                $posFields + [
                    'pin_code'              => $data['pin_code'],
                    'failed_login_attempts' => 0,
                    'locked_until'          => null,
                ],
            );

            return;
        }

        // No PIN supplied: update settings only if an account already exists.
        // (pin_code is NOT NULL, so we cannot create an account without a PIN.)
        $cashier?->update($posFields);
    }

    public function sendInvitation(Request $request): RedirectResponse
    {
        $store = $request->user()->getActiveStore();
        abort_if($store === null, 422, 'No active store.');

        $validated = $request->validate([
            'email'         => ['required', 'email', 'max:255'],
            'store_role_id' => [
                'required', 'string',
                Rule::exists('store_roles', 'id')->where('store_id', $store->id),
            ],
        ]);

        $role = StoreRole::query()
            ->where('store_id', $store->id)
            ->findOrFail($validated['store_role_id']);

        $existing = StoreInvitation::query()
            ->where('store_id', $store->id)
            ->where('email', $validated['email'])
            ->where('status', 'pending')
            ->where('expires_at', '>', now())
            ->first();

        if ($existing !== null) {
            return back()->withErrors(['email' => 'A pending invitation already exists for this email.']);
        }

        // Legacy invitation column only. The assigned StoreRole decides access.
        $coarseRole = $role->grantsDashboardAccess() ? 'manager' : 'cashier';

        $invitation = StoreInvitation::create([
            'store_id'      => $store->id,
            'invited_by'    => $request->user()->id,
            'email'         => $validated['email'],
            'role'          => $coarseRole,
            'store_role_id' => $role->id,
            'token'         => StoreInvitation::generateToken(),
            'status'        => 'pending',
            'expires_at'    => now()->addHours(72),
        ]);

        try {
            Mail::to($invitation->email)->queue(new InvitationMail($invitation, $store, $request->user()));
        } catch (Throwable $e) {
            Log::warning('Invitation email queue failed', [
                'invitation_id' => $invitation->id,
                'error'         => $e->getMessage(),
            ]);
        }

        return redirect()->route('dashboard.team.index')->with('success', "Invitation sent to {$invitation->email}.");
    }

    public function removeMember(Request $request, StoreMember $member): RedirectResponse
    {
        $store = $request->user()->getActiveStore();
        abort_if($store === null || $member->store_id !== $store->id, 403);

        if ($member->user_id === $request->user()->id) {
            return back()->withErrors(['member' => 'You cannot remove yourself.']);
        }

        if ($this->isWorkspaceOwner($store, $member->user_id)) {
            return back()->withErrors(['member' => 'The workspace owner cannot be removed from a store.']);
        }

        $member->update(['is_active' => false]);

        return back()->with('success', 'Team member removed.');
    }

    public function revokeInvitation(Request $request, StoreInvitation $invitation): RedirectResponse
    {
        $store = $request->user()->getActiveStore();
        abort_if($store === null || $invitation->store_id !== $store->id, 403);

        $invitation->update(['status' => 'expired']);

        return back()->with('success', 'Invitation revoked.');
    }
    private function isWorkspaceOwner(Store $store, string $userId): bool
    {
        if ($store->organization !== null) {
            return $store->organization->owner_user_id === $userId;
        }

        return $store->user_id === $userId;
    }

    private function effectiveRoleName(Store $store, StoreMember $member): string
    {
        if ($this->isWorkspaceOwner($store, $member->user_id)) {
            return $store->organization !== null ? 'Organization owner' : 'Store owner';
        }

        if ($store->organization !== null) {
            $workspaceMembership = $member->user?->organizationMemberships
                ?->first(fn ($membership) => $membership->organization_id === $store->organization_id && $membership->is_active);

            if ($workspaceMembership?->canManageOrganization()) {
                return 'Organization admin';
            }
        }

        return $member->storeRole?->name ?? ucfirst((string) $member->role);
    }

}
