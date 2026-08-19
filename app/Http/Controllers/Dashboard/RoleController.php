<?php

declare(strict_types=1);

namespace App\Http\Controllers\Dashboard;

use App\Http\Controllers\Controller;
use App\Models\StoreRole;
use App\Support\PermissionCatalog;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

class RoleController extends Controller
{
    public function index(Request $request): Response
    {
        $store = $request->user()->getActiveStore();
        abort_if($store === null, 422, 'No active store.');

        $store->ensureDefaultRoles();

        $roles = $store->roles()
            ->withCount(['members' => fn ($q) => $q->where('is_active', true)])
            ->orderByDesc('is_system')
            ->orderBy('name')
            ->get()
            ->map(fn (StoreRole $role) => [
                'id'               => $role->id,
                'name'             => $role->name,
                'description'      => $role->description,
                'is_system'        => $role->is_system,
                'is_locked'        => $role->is_locked,
                'permissions'      => $role->permissionList(),
                'permission_count' => count($role->effectivePermissions()),
                'member_count'     => $role->members_count,
            ]);

        return Inertia::render('Dashboard/Roles/Index', [
            'store' => ['id' => $store->id, 'name' => $store->name],
            'roles' => $roles,
        ]);
    }

    public function create(Request $request): Response
    {
        $store = $request->user()->getActiveStore();
        abort_if($store === null, 422, 'No active store.');

        return Inertia::render('Dashboard/Roles/Form', [
            'store'   => ['id' => $store->id, 'name' => $store->name],
            'catalog' => PermissionCatalog::groups(),
            'role'    => null,
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $store = $request->user()->getActiveStore();
        abort_if($store === null, 422, 'No active store.');

        $validated = $this->validateRole($request, $store->id);

        $store->roles()->create([
            'name'        => $validated['name'],
            'slug'        => $this->uniqueSlug($store->id, $validated['name']),
            'description' => $validated['description'] ?? null,
            'is_system'   => false,
            'is_locked'   => false,
            'permissions' => PermissionCatalog::sanitize($validated['permissions'] ?? []),
        ]);

        return redirect()->route('dashboard.roles.index')->with('success', "Role \"{$validated['name']}\" created.");
    }

    public function edit(Request $request, StoreRole $role): Response
    {
        $store = $this->authorizeRole($request, $role);

        return Inertia::render('Dashboard/Roles/Form', [
            'store'   => ['id' => $store->id, 'name' => $store->name],
            'catalog' => PermissionCatalog::groups(),
            'role'    => [
                'id'          => $role->id,
                'name'        => $role->name,
                'description' => $role->description,
                'is_system'   => $role->is_system,
                'is_locked'   => $role->is_locked,
                'permissions' => $role->permissionList(),
            ],
        ]);
    }

    public function update(Request $request, StoreRole $role): RedirectResponse
    {
        $this->authorizeRole($request, $role);

        $validated = $this->validateRole($request, $role->store_id, $role->id);

        // Locked roles (Administrator) keep name/permissions fixed.
        if ($role->is_locked) {
            return back()->withErrors(['name' => 'This role is locked and cannot be edited.']);
        }

        // System roles keep their name/slug; only custom roles can be renamed.
        if (! $role->is_system) {
            $role->name = $validated['name'];
            $role->slug = $this->uniqueSlug($role->store_id, $validated['name'], $role->id);
        }

        $role->description = $validated['description'] ?? null;
        $role->permissions = PermissionCatalog::sanitize($validated['permissions'] ?? []);
        $role->save();

        return redirect()->route('dashboard.roles.index')->with('success', "Role \"{$role->name}\" updated.");
    }

    public function destroy(Request $request, StoreRole $role): RedirectResponse
    {
        $this->authorizeRole($request, $role);

        if (! $role->isDeletable()) {
            return back()->withErrors(['role' => 'System roles cannot be deleted.']);
        }

        if ($role->members()->where('is_active', true)->exists()) {
            return back()->withErrors(['role' => 'Reassign members before deleting this role.']);
        }

        $role->delete();

        return back()->with('success', 'Role deleted.');
    }

    /**
     * @return array{name: string, description: ?string, permissions: array<int, string>}
     */
    private function validateRole(Request $request, string $storeId, ?string $ignoreId = null): array
    {
        return $request->validate([
            'name' => [
                'required', 'string', 'max:255',
                Rule::unique('store_roles', 'name')
                    ->where('store_id', $storeId)
                    ->ignore($ignoreId),
            ],
            'description'   => ['nullable', 'string', 'max:255'],
            'permissions'   => ['array'],
            'permissions.*' => ['string', Rule::in([PermissionCatalog::WILDCARD, ...PermissionCatalog::keys()])],
        ]);
    }

    private function authorizeRole(Request $request, StoreRole $role): \App\Models\Store
    {
        $store = $request->user()->getActiveStore();
        abort_if($store === null || $role->store_id !== $store->id, 403);

        return $store;
    }

    private function uniqueSlug(string $storeId, string $name, ?string $ignoreId = null): string
    {
        $base = Str::slug($name) ?: 'role';
        $slug = $base;
        $i    = 1;

        while (StoreRole::where('store_id', $storeId)
            ->where('slug', $slug)
            ->when($ignoreId, fn ($q) => $q->where('id', '!=', $ignoreId))
            ->exists()
        ) {
            $slug = "{$base}-{$i}";
            $i++;
        }

        return $slug;
    }
}
