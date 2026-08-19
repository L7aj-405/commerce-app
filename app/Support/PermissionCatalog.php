<?php

declare(strict_types=1);

namespace App\Support;

/**
 * Central catalogue of every granular permission a store role can grant.
 *
 * Permissions are version-controlled here (NOT user-editable) so route
 * middleware and Blade/Inertia checks can rely on a fixed set of keys.
 * Custom store roles simply store a subset of these keys in their JSON
 * `permissions` column. The wildcard '*' grants everything.
 */
final class PermissionCatalog
{
    public const WILDCARD = '*';

    /**
     * Grouped catalogue for the role-builder UI.
     *
     * @return array<int, array{group: string, label: string, permissions: array<int, array{key: string, label: string, description: string}>}>
     */
    public static function groups(): array
    {
        return [
            [
                'group' => 'orders',
                'label' => 'Orders',
                'permissions' => [
                    ['key' => 'orders.view',    'label' => 'View orders',    'description' => 'See the orders list and order details.'],
                    // Coarse permission: implies all four department keys below,
                    // so existing Manager roles keep working unchanged.
                    ['key' => 'orders.manage',  'label' => 'Manage orders',  'description' => 'Update order status and details across every department.'],
                    ['key' => 'orders.confirm', 'label' => 'Confirm orders', 'description' => 'Confirmation desk: approve or cancel newly synced orders.'],
                    ['key' => 'orders.fulfil',   'label' => 'Fulfil orders',  'description' => 'Warehouse: pick, pack and mark orders ready for delivery.'],
                    ['key' => 'orders.dispatch', 'label' => 'Dispatch orders', 'description' => 'Logistics: assign carriers, track shipments and confirm delivery.'],
                    ['key' => 'orders.deliver',  'label' => 'Deliver orders',  'description' => 'Delivery agent: work an assigned delivery queue, collect COD and confirm or fail delivery.'],
                    ['key' => 'orders.return',  'label' => 'Flag returns',   'description' => 'Mark a dispatched or delivered order as returned.'],
                    ['key' => 'orders.inspect', 'label' => 'Inspect returns', 'description' => 'Inspect returned goods and route them to active or damaged stock.'],
                ],
            ],
            [
                'group' => 'products',
                'label' => 'Products',
                'permissions' => [
                    ['key' => 'products.view',   'label' => 'View products',   'description' => 'Browse the product catalogue.'],
                    ['key' => 'products.manage', 'label' => 'Manage products', 'description' => 'Create, edit, delete and sync products.'],
                ],
            ],
            [
                'group' => 'stock',
                'label' => 'Stock',
                'permissions' => [
                    ['key' => 'stock.view',   'label' => 'View stock',   'description' => 'See stock levels and movements.'],
                    ['key' => 'stock.adjust', 'label' => 'Adjust stock', 'description' => 'Change stock quantities.'],
                ],
            ],
            [
                'group' => 'invoicing',
                'label' => 'Invoicing & Delivery',
                'permissions' => [
                    ['key' => 'factures.view',  'label' => 'View invoices',     'description' => 'See factures and download PDFs.'],
                    ['key' => 'invoices.issue', 'label' => 'Issue invoices',     'description' => 'Generate/finalize an invoice from an order.'],
                    ['key' => 'invoices.amend', 'label' => 'Amend invoices',     'description' => 'Edit a finalized invoice (requires a reason; fully audited).'],
                    ['key' => 'invoices.void',  'label' => 'Void invoices',      'description' => 'Cancel a finalized invoice.'],
                    ['key' => 'bon.manage',     'label' => 'Manage deliveries',  'description' => 'Update bon de livraison status.'],
                ],
            ],
            [
                'group' => 'pos',
                'label' => 'Point of Sale',
                'permissions' => [
                    ['key' => 'pos.access', 'label' => 'Access POS terminal', 'description' => 'Sign in to and use the POS terminal.'],
                ],
            ],
            [
                'group' => 'warehouses',
                'label' => 'Warehouses',
                'permissions' => [
                    ['key' => 'warehouses.manage', 'label' => 'Manage warehouses', 'description' => 'Create and edit warehouses.'],
                    ['key' => 'inventory.transfers.receive', 'label' => 'Receive transfers', 'description' => 'Receive an inbound inventory transfer into a warehouse.'],
                ],
            ],
            [
                'group' => 'administration',
                'label' => 'Administration',
                'permissions' => [
                    ['key' => 'team.manage',         'label' => 'Manage team',         'description' => 'Invite and remove members.'],
                    ['key' => 'roles.manage',        'label' => 'Manage roles',        'description' => 'Create and edit custom roles and permissions.'],
                    ['key' => 'stores.manage',       'label' => 'Manage stores',       'description' => 'Create, edit and delete stores.'],
                    ['key' => 'settings.manage',     'label' => 'Manage settings',     'description' => 'Change store settings.'],
                    ['key' => 'integrations.manage', 'label' => 'Manage integrations', 'description' => 'Connect WooCommerce, Shopify, YouCan and WhatsApp.'],
                ],
            ],
        ];
    }

    /**
     * Flat list of every valid permission key (excluding the wildcard).
     *
     * @return array<int, string>
     */
    public static function keys(): array
    {
        $keys = [];

        foreach (self::groups() as $group) {
            foreach ($group['permissions'] as $permission) {
                $keys[] = $permission['key'];
            }
        }

        return $keys;
    }

    public static function isValid(string $permission): bool
    {
        return $permission === self::WILDCARD || in_array($permission, self::keys(), true);
    }

    /**
     * Sanitise an arbitrary array down to valid permission keys.
     *
     * @param  array<int, mixed>  $permissions
     * @return array<int, string>
     */
    public static function sanitize(array $permissions): array
    {
        return array_values(array_unique(array_filter(
            $permissions,
            static fn ($permission): bool => is_string($permission) && self::isValid($permission),
        )));
    }

    /**
     * Default system roles seeded for every store.
     *
     * @return array<int, array{name: string, description: string, permissions: array<int, string>, locked: bool}>
     */
    public static function defaultRoles(): array
    {
        return [
            [
                'name'        => 'Administrator',
                'description' => 'Full access to everything in the store.',
                'permissions' => [self::WILDCARD],
                'locked'      => true,
            ],
            [
                'name'        => 'Manager',
                'description' => 'Runs day-to-day operations. No access to team, roles, settings or integrations.',
                'permissions' => [
                    'orders.view', 'orders.manage',
                    'products.view', 'products.manage',
                    'stock.view', 'stock.adjust',
                    'factures.view', 'invoices.issue', 'bon.manage',
                    'pos.access',
                ],
                'locked' => false,
            ],
            [
                'name'        => 'Cashier',
                'description' => 'POS terminal only — never the dashboard.',
                'permissions' => ['pos.access'],
                'locked'      => false,
            ],
            [
                'name'        => 'Viewer',
                'description' => 'Read-only access to orders, products, stock and invoices.',
                'permissions' => ['orders.view', 'products.view', 'stock.view', 'factures.view'],
                'locked'      => false,
            ],
            // The three order-lifecycle departments. Each sees the whole board
            // but can only act within its own phase — the board hides the tabs
            // a role cannot work in.
            [
                'name'        => 'Confirmation agent',
                'description' => 'Confirmation desk: approves or cancels newly synced orders.',
                'permissions' => ['orders.view', 'orders.confirm'],
                'locked'      => false,
            ],
            [
                'name'        => 'Warehouse',
                'description' => 'Packs, dispatches and delivers confirmed orders.',
                'permissions' => ['orders.view', 'orders.fulfil', 'orders.return', 'stock.view', 'inventory.transfers.receive'],
                'locked'      => false,
            ],
            [
                'name'        => 'Dispatcher',
                'description' => 'Logistics: assigns couriers or delivery agents and confirms delivery.',
                // Also holds orders.return: a refused delivery is flagged from
                // the dispatch board, not the warehouse.
                'permissions' => ['orders.view', 'orders.dispatch', 'orders.return'],
                'locked'      => false,
            ],
            [
                'name'        => 'Delivery agent',
                'description' => 'Works their own assigned delivery queue on a phone: COD, mark delivered or failed.',
                // No orders.view: a driver sees only their own deliveries, never
                // the department boards. orders.deliver alone grants dashboard
                // access (it is not pos.access), so getActiveStore resolves.
                'permissions' => ['orders.deliver'],
                'locked'      => false,
            ],
            [
                'name'        => 'Inspector',
                'description' => 'Inspects returned goods and routes them to active or damaged stock.',
                'permissions' => ['orders.view', 'orders.inspect', 'stock.view', 'stock.adjust'],
                'locked'      => false,
            ],
        ];
    }

    /**
     * Whether a set of permissions grants dashboard access
     * (anything beyond POS-only). This is the contextual dashboard gate;
     * users.role is deliberately not consulted for store operations.
     *
     * @param  array<int, string>  $permissions
     */
    public static function grantsDashboardAccess(array $permissions): bool
    {
        if (in_array(self::WILDCARD, $permissions, true)) {
            return true;
        }

        foreach ($permissions as $permission) {
            if ($permission !== 'pos.access') {
                return true;
            }
        }

        return false;
    }
}
