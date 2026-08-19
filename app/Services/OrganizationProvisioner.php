<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Organization;
use App\Models\OrganizationMember;
use App\Models\User;
use Illuminate\Support\Facades\DB;

class OrganizationProvisioner
{
    /**
     * Pick the current manageable organization for a newly-created store, or
     * create a fresh workspace when the user has no suitable active context.
     */
    public function forNewStore(User $user, string $storeName): Organization
    {
        $activeOrganization = $user->getActiveStore()?->organization;

        if ($activeOrganization !== null && $user->canManageOrganization($activeOrganization)) {
            return $activeOrganization;
        }

        return $this->createOwnedOrganization($user, $storeName);
    }

    public function createOwnedOrganization(User $user, string $name, string $type = Organization::TYPE_MERCHANT): Organization
    {
        return DB::transaction(function () use ($user, $name, $type): Organization {
            $organization = Organization::create([
                'owner_user_id' => $user->id,
                'type' => $type,
                'name' => $name,
                'status' => 'active',
            ]);

            OrganizationMember::create([
                'organization_id' => $organization->id,
                'user_id' => $user->id,
                'role' => OrganizationMember::ROLE_OWNER,
                'is_active' => true,
                'joined_at' => now(),
            ]);

            return $organization;
        });
    }

    public function ensureMember(
        Organization $organization,
        User $user,
        string $role = OrganizationMember::ROLE_MEMBER,
    ): OrganizationMember {
        $membership = OrganizationMember::firstOrNew([
            'organization_id' => $organization->id,
            'user_id' => $user->id,
        ]);

        if (! $membership->exists) {
            $membership->joined_at = now();
            $membership->role = $role;
        } elseif ($this->roleRank($role) > $this->roleRank((string) $membership->role)) {
            // Synchronisation may upgrade membership but never silently
            // downgrade an existing workspace owner/admin. Downgrades belong
            // in an explicit organization-admin workflow, not store syncing.
            $membership->role = $role;
        }

        $membership->is_active = true;
        $membership->save();

        return $membership;
    }

    private function roleRank(string $role): int
    {
        return match ($role) {
            OrganizationMember::ROLE_OWNER => 30,
            OrganizationMember::ROLE_ADMIN => 20,
            default => 10,
        };
    }
}
