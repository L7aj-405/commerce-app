/**
 * Curates the compact icon rail's contents per role, on top of the existing
 * permission-filtered nav item list (`allItems` in SaasLayout.jsx). This is
 * purely additive — it only ever picks from items that already passed the
 * real `auth.permissions` check; it never grants access to anything.
 *
 * Role slugs come from `User::accessProfileForStore()` (see
 * HandleInertiaRequests -> auth.access.roleSlug): owner-tier slugs are
 * computed (organization-owner/organization-admin/store-owner/agency-operator),
 * everything else is a StoreRole::slug (manager, cashier, viewer,
 * confirmation-agent, warehouse, supervisor, dispatcher, delivery-agent,
 * inspector, administrator, or any custom role a store owner created).
 */

// The Settings destination is deliberately absent from every list below —
// it always gets its own fixed slot at the bottom of the rail (the
// `utilityItem` in SaasLayout.jsx/PermissionAwareRail.jsx), so including it
// here too would render the gear icon twice. PermissionAwareRail also
// defensively filters it out even if a future edit reintroduces it.
// Owner/admin gets one icon per important domain — Dashboard, Orders,
// Products, Inventory, Delivery, Finance, Integrations (+ Settings, always
// the separate utility slot). Finance in particular must never be missing
// here: it's core to what an owner/admin checks daily.
const OWNER_TIER_HREFS = [
    '/dashboard',
    '/dashboard/orders/manage',
    '/dashboard/products',
    '/dashboard/stock',
    '/dashboard/departments/dispatch', // Delivery Board — the ops-facing "Delivery" destination.
    '/dashboard/finance',
    '/dashboard/integrations',
];

export const ROLE_SHORTCUT_HREFS = {
    'organization-owner': OWNER_TIER_HREFS,
    'organization-admin': OWNER_TIER_HREFS,
    'store-owner': OWNER_TIER_HREFS,
    administrator: OWNER_TIER_HREFS,
    'agency-operator': OWNER_TIER_HREFS,

    manager: [
        '/dashboard',
        '/dashboard/departments/confirmation',
        '/dashboard/departments/packing',
        '/dashboard/departments/dispatch',
        '/dashboard/operations/waiting-stock',
        '/dashboard/orders/returns',
    ],
    supervisor: [
        '/dashboard',
        '/dashboard/departments/confirmation',
        '/dashboard/departments/packing',
        '/dashboard/departments/dispatch',
        '/dashboard/operations/waiting-stock',
        '/dashboard/orders/returns',
    ],

    'confirmation-agent': [
        '/dashboard/departments/confirmation',
        '/dashboard/orders/manage',
    ],

    // Picker/packer — the hands-on warehouse worker.
    warehouse: [
        '/dashboard/departments/packing',
        '/dashboard/operations/picking',
        '/dashboard/operations/packing',
        '/dashboard/operations/ready-delivery',
    ],

    dispatcher: [
        '/dashboard/departments/dispatch',
        '/dashboard/orders/manage',
        '/dashboard/orders/returns',
    ],

    // Renders DeliveryAgentLayout, not SaasLayout — kept for completeness,
    // never actually load-bearing.
    'delivery-agent': ['/dashboard/my-deliveries'],

    inspector: [
        '/dashboard/orders/returns',
        '/dashboard/stock',
    ],

    cashier: ['/pos'],

    viewer: [
        '/dashboard/orders/manage',
        '/dashboard/products',
        '/dashboard/stock',
    ],
};

// Same href set as the owner tier — kept as one source of truth (see
// Task 5's navigation-consistency goal) rather than two literal copies that
// could silently drift apart.
export const DEFAULT_SHORTCUT_HREFS = OWNER_TIER_HREFS;

/**
 * @param {Array<{href: string}>} allItems Already permission-filtered nav items.
 * @param {string|null} roleSlug
 * @param {number} max
 */
export function curateRailItems(allItems, roleSlug, max = 7) {
    const byHref = new Map(allItems.map((item) => [item.href, item]));
    const candidates = ROLE_SHORTCUT_HREFS[roleSlug] ?? DEFAULT_SHORTCUT_HREFS;

    const picked = [];
    const seen = new Set();

    const take = (hrefs) => {
        for (const href of hrefs) {
            if (picked.length >= max) break;
            if (seen.has(href)) continue;
            const item = byHref.get(href);
            if (! item) continue;
            picked.push(item);
            seen.add(href);
        }
    };

    take(candidates);
    if (picked.length < 3) take(DEFAULT_SHORTCUT_HREFS);

    return picked;
}
