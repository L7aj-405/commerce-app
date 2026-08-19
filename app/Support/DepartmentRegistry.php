<?php

declare(strict_types=1);

namespace App\Support;

use App\Models\Store;
use App\Models\User;

/**
 * The four operational departments, in workflow order.
 *
 * Single source of truth for the department switcher: the nav, the route
 * permissions and the "which queue does this phase belong to" lookups all read
 * from here, so adding a department is one entry rather than four edits.
 *
 * A department is a phase of App\Enums\FulfillmentStatus plus the permission
 * that lets someone work it — not a database entity.
 */
class DepartmentRegistry
{
    /** @return array<int, array{key:string,phase:string,label:string,short:string,permission:string,href:string,icon:string}> */
    public static function all(): array
    {
        return [
            [
                'key'        => 'confirmation',
                'phase'      => 'confirmation',
                'label'      => 'Confirmation',
                'short'      => 'Confirm incoming orders',
                'permission' => 'orders.confirm',
                'href'       => '/dashboard/departments/confirmation',
                'icon'       => 'ClipboardCheck',
            ],
            [
                'key'        => 'packing',
                'phase'      => 'fulfillment',
                'label'      => 'Pick & Pack',
                'short'      => 'Prepare orders for dispatch',
                'permission' => 'orders.fulfil',
                'href'       => '/dashboard/departments/packing',
                'icon'       => 'PackageCheck',
            ],
            [
                'key'        => 'dispatch',
                'phase'      => 'delivery',
                'label'      => 'Delivery',
                'short'      => 'Assign carriers and confirm delivery',
                'permission' => 'orders.dispatch',
                'href'       => '/dashboard/departments/dispatch',
                'icon'       => 'Truck',
            ],
            [
                'key'        => 'returns',
                'phase'      => 'returns',
                'label'      => 'Returns',
                'short'      => 'Inspect and sort returned goods',
                'permission' => 'orders.inspect',
                'href'       => '/dashboard/orders/returns',
                'icon'       => 'Undo2',
            ],
        ];
    }

    /**
     * Departments this user may work in.
     *
     * Store owners and support-scoped platform admins hold the whole catalogue via
     * User::isPrivilegedFor(), so they see all four without a special case here.
     * `orders.manage` also opens every department — it is the coarse permission
     * existing Manager roles already carry.
     *
     * @return array<int, array<string, mixed>>
     */
    public static function visibleTo(User $user, ?Store $store): array
    {
        if ($store === null) {
            return [];
        }

        $all = $user->hasStorePermission($store, 'orders.manage');

        return array_values(array_filter(
            self::all(),
            fn (array $d) => $all || $user->hasStorePermission($store, $d['permission']),
        ));
    }

    public static function permissionFor(string $phase): string
    {
        foreach (self::all() as $department) {
            if ($department['phase'] === $phase) {
                return $department['permission'];
            }
        }

        return 'orders.manage';
    }
}
