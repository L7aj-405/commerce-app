/**
 * Contextual topbar tabs, keyed by the current URL's prefix. Replaces the old
 * fixed Dashboard/Orders/Products/Stock/Integrations center nav (which just
 * duplicated the icon rail) with tabs scoped to whichever module the user is
 * actually in.
 *
 * Every href below is a REAL route registered in routes/dashboard.php — no
 * tab is invented for a page that doesn't exist (e.g. Products has no
 * dedicated Imports/Publish/Channel-listings page, so those are omitted
 * rather than linking nowhere). The Dashboard root is deliberately absent —
 * it shows the command search bar instead of tabs.
 */
export const CONTEXTUAL_NAV = [
    {
        match: '/dashboard/orders',
        tabs: [
            { label: 'All Orders', href: '/dashboard/orders/manage', perm: 'orders.view' },
            { label: 'Confirmation', href: '/dashboard/departments/confirmation', perm: 'orders.confirm' },
            { label: 'Pick & Pack', href: '/dashboard/departments/packing', perm: 'orders.fulfil' },
            { label: 'Delivery', href: '/dashboard/departments/dispatch', perm: 'orders.dispatch' },
            { label: 'Returns', href: '/dashboard/orders/returns', perm: 'orders.inspect' },
        ],
    },
    {
        match: '/dashboard/departments',
        tabs: [
            { label: 'All Orders', href: '/dashboard/orders/manage', perm: 'orders.view' },
            { label: 'Confirmation', href: '/dashboard/departments/confirmation', perm: 'orders.confirm' },
            { label: 'Pick & Pack', href: '/dashboard/departments/packing', perm: 'orders.fulfil' },
            { label: 'Delivery', href: '/dashboard/departments/dispatch', perm: 'orders.dispatch' },
            { label: 'Returns', href: '/dashboard/orders/returns', perm: 'orders.inspect' },
        ],
    },
    {
        match: '/dashboard/operations',
        tabs: [
            { label: 'Waiting for stock', href: '/dashboard/operations/waiting-stock', perm: 'operations.supervise' },
            { label: 'Picking', href: '/dashboard/operations/picking', perm: 'operations.supervise' },
            { label: 'Packing', href: '/dashboard/operations/packing', perm: 'operations.supervise' },
            { label: 'Ready for Dispatch', href: '/dashboard/operations/ready-delivery', perm: 'operations.supervise' },
            { label: 'Transfers', href: '/dashboard/operations/transfers', perm: 'operations.supervise' },
        ],
    },
    {
        match: '/dashboard/products',
        tabs: [
            { label: 'Products', href: '/dashboard/products', perm: 'products.view' },
            { label: 'Stock', href: '/dashboard/stock', perm: 'stock.view' },
        ],
    },
    {
        match: '/dashboard/stock',
        tabs: [
            { label: 'Stock', href: '/dashboard/stock', perm: 'stock.view' },
            { label: 'Movements', href: '/dashboard/stock/movements', perm: 'stock.view' },
            { label: 'Transfers', href: '/dashboard/stock/transfers', perm: 'stock.view' },
            { label: 'Warehouses', href: '/dashboard/warehouses', perm: 'warehouses.manage' },
        ],
    },
    {
        match: '/dashboard/warehouses',
        tabs: [
            { label: 'Stock', href: '/dashboard/stock', perm: 'stock.view' },
            { label: 'Warehouses', href: '/dashboard/warehouses', perm: 'warehouses.manage' },
            { label: 'Transfers', href: '/dashboard/stock/transfers', perm: 'stock.view' },
        ],
    },
    {
        match: '/dashboard/integrations',
        tabs: [
            { label: 'E-commerce Platforms', href: '/dashboard/integrations?tab=commerce', perm: 'integrations.manage' },
            { label: 'Delivery Providers', href: '/dashboard/integrations?tab=delivery', perm: ['delivery.connections.manage', 'integrations.manage'] },
            { label: 'Other Tools', href: '/dashboard/integrations?tab=tools', perm: 'integrations.manage' },
        ],
    },
    {
        match: '/dashboard/delivery-connections',
        tabs: [
            { label: 'E-commerce Platforms', href: '/dashboard/integrations?tab=commerce', perm: 'integrations.manage' },
            { label: 'Delivery Providers', href: '/dashboard/integrations?tab=delivery', perm: ['delivery.connections.manage', 'integrations.manage'] },
            { label: 'Other Tools', href: '/dashboard/integrations?tab=tools', perm: 'integrations.manage' },
        ],
    },
    {
        match: '/dashboard/settings',
        tabs: [
            { label: 'Store Settings', href: '/dashboard/settings', perm: 'settings.manage' },
            { label: 'Team', href: '/dashboard/team', perm: 'team.manage' },
            { label: 'Roles', href: '/dashboard/roles', perm: 'roles.manage' },
        ],
    },
    {
        match: '/dashboard/team',
        tabs: [
            { label: 'Store Settings', href: '/dashboard/settings', perm: 'settings.manage' },
            { label: 'Team', href: '/dashboard/team', perm: 'team.manage' },
            { label: 'Roles', href: '/dashboard/roles', perm: 'roles.manage' },
        ],
    },
    {
        match: '/dashboard/roles',
        tabs: [
            { label: 'Store Settings', href: '/dashboard/settings', perm: 'settings.manage' },
            { label: 'Team', href: '/dashboard/team', perm: 'team.manage' },
            { label: 'Roles', href: '/dashboard/roles', perm: 'roles.manage' },
        ],
    },
];

function hasPermission(permissions, permission) {
    if (! permission) return true;
    if (permissions.includes('*')) return true;
    return Array.isArray(permission)
        ? permission.some((item) => permissions.includes(item))
        : permissions.includes(permission);
}

/**
 * Longest-prefix match on the current URL's pathname, tabs filtered down to
 * what the caller may access. Returns [] when nothing matches or only one
 * tab would remain (a single tab is never useful).
 */
export function resolveContextualTabs(currentUrl, permissions) {
    const pathname = String(currentUrl ?? '').split('?')[0];

    const config = CONTEXTUAL_NAV
        .filter((entry) => pathname === entry.match || pathname.startsWith(`${entry.match}/`))
        .sort((a, b) => b.match.length - a.match.length)[0];

    if (! config) return [];

    const tabs = config.tabs.filter((tab) => hasPermission(permissions, tab.perm));
    return tabs.length > 1 ? tabs : [];
}

export function isContextualTabActive(currentUrl, href) {
    const [currentPath, currentQuery] = String(currentUrl ?? '').split('?');
    const [targetPath, targetQuery] = String(href ?? '').split('?');

    const pathMatches = currentPath.replace(/\/+$/, '') === targetPath.replace(/\/+$/, '');
    if (! pathMatches) return false;
    if (! targetQuery) return ! currentQuery || ! currentQuery.includes('tab=');

    const currentTab = new URLSearchParams(currentQuery).get('tab') ?? 'commerce';
    const targetTab = new URLSearchParams(targetQuery).get('tab');
    return currentTab === targetTab;
}
