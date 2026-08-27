import { useEffect, useMemo, useState } from 'react';
import { router, usePage } from '@inertiajs/react';
import {
    Home, ShoppingCart, Package, Layers, Monitor, FileText, Truck,
    Settings, Plug, Users, Building2, ShieldCheck, Undo2,
    ClipboardCheck, PackageCheck, Navigation, LifeBuoy, ClipboardList,
    PackageSearch, ArrowLeftRight,
} from 'lucide-react';
import PageHeader from '@/Components/PageHeader';
import ToastNotification from '@/Components/ToastNotification';
import useOrderNotifications from '@/Hooks/useOrderNotifications';
import PremiumAppShell from '@/Components/PremiumDashboard/PremiumAppShell';
import FloatingTopbar from '@/Components/PremiumDashboard/FloatingTopbar';
import PermissionAwareRail from '@/Components/PremiumDashboard/PermissionAwareRail';
import FullNavigationDrawer from '@/Components/PremiumDashboard/FullNavigationDrawer';
import CommandPalette from '@/Components/PremiumDashboard/CommandPalette';
import { curateRailItems } from '@/Support/roleShortcuts';
import { resolveContextualTabs } from '@/Support/contextualNav';

// `domain` groups these sections in the full navigation drawer (Overview /
// Commerce / Orders / Fulfillment / Inventory / Integrations / Settings) —
// purely a display grouping. Every section/item `label` below is unchanged
// from before and must stay that way: AdminOperationsNavigationClarityTest
// and IntegrationNavigationTest assert these exact strings against this
// file's source.
const NAV_SECTIONS = [
    { label: 'Overview', domain: 'Overview', items: [
        { label: 'Dashboard', href: '/dashboard', icon: Home },
    ]},
    { label: 'Commerce', domain: 'Commerce', items: [
        { label: 'Products', href: '/dashboard/products', icon: Package, perm: 'products.view' },
    ]},
    { label: 'Orders', domain: 'Orders', items: [
        { label: 'All orders', href: '/dashboard/orders/manage', icon: ShoppingCart, perm: 'orders.view' },
        { label: 'Confirmation Desk', href: '/dashboard/departments/confirmation', icon: ClipboardCheck, perm: 'orders.confirm' },
    ]},
    { label: 'Fulfillment Workboards', domain: 'Fulfillment', items: [
        { label: 'Pick & Pack Workbench', href: '/dashboard/departments/packing', icon: PackageCheck, perm: 'orders.fulfil' },
        { label: 'Delivery Board', href: '/dashboard/departments/dispatch', icon: Truck, perm: 'orders.dispatch' },
        { label: 'My deliveries', href: '/dashboard/my-deliveries', icon: Navigation, perm: 'orders.deliver' },
        { label: 'Returns Desk', href: '/dashboard/orders/returns', icon: Undo2, perm: 'orders.inspect' },
    ]},
    { label: 'Supervisor Queues', domain: 'Fulfillment', items: [
        { label: 'Waiting for stock', href: '/dashboard/operations/waiting-stock', icon: ClipboardList, perm: 'operations.supervise' },
        { label: 'Picking Queue', href: '/dashboard/operations/picking', icon: PackageSearch, perm: 'operations.supervise' },
        { label: 'Packing Queue', href: '/dashboard/operations/packing', icon: Package, perm: 'operations.supervise' },
        { label: 'Ready for Dispatch', href: '/dashboard/operations/ready-delivery', icon: PackageCheck, perm: 'operations.supervise' },
        { label: 'Transfer Receiving', href: '/dashboard/operations/transfers', icon: ArrowLeftRight, perm: 'operations.supervise' },
    ]},
    { label: 'Inventory', domain: 'Inventory', items: [
        { label: 'Warehouses', href: '/dashboard/warehouses', icon: Building2, perm: 'warehouses.manage' },
        { label: 'Stock', href: '/dashboard/stock', icon: Layers, perm: 'stock.view' },
        { label: 'Transfers', href: '/dashboard/stock/transfers', icon: ArrowLeftRight, perm: 'stock.view' },
    ]},
    { label: 'POS System', domain: 'Commerce', items: [
        { label: 'POS Terminal', href: '/pos', icon: Monitor, perm: 'pos.access' },
        { label: 'Factures', href: '/dashboard/factures', icon: FileText, perm: 'factures.view' },
        { label: 'Bon de Livraison', href: '/dashboard/bon-de-livraison', icon: Truck, perm: 'factures.view' },
    ]},
    { label: 'Team', domain: 'Settings', items: [
        { label: 'Team Members', href: '/dashboard/team', icon: Users, perm: 'team.manage' },
        { label: 'Roles', href: '/dashboard/roles', icon: ShieldCheck, perm: 'roles.manage' },
    ]},
    { label: 'Settings', domain: 'Settings', items: [
        { label: 'My Stores', href: '/dashboard/stores', icon: Building2, perm: 'stores.manage' },
        { label: 'Store Settings', href: '/dashboard/settings', icon: Settings, perm: 'settings.manage' },
        {
            label: 'Integrations', href: '/dashboard/integrations', icon: Plug,
            perm: ['integrations.manage', 'delivery.connections.manage'],
            activeOn: ['/dashboard/delivery-connections'],
            domain: 'Integrations',
        },
    ]},
];

export default function SaasLayout({ children, pageHeader }) {
    const { url, props } = usePage();
    const [navigationOpen, setNavigationOpen] = useState(false);
    const [searchOpen, setSearchOpen] = useState(false);
    const permissions = props.auth?.permissions ?? [];
    const roleSlug = props.auth?.access?.roleSlug ?? null;
    const orderNotif = useOrderNotifications();
    const can = (permission) => hasNavPermission(permissions, permission);

    const sections = useMemo(
        () => NAV_SECTIONS
            .map((section) => ({ ...section, items: section.items.filter((item) => can(item.perm)) }))
            .filter((section) => section.items.length > 0),
        [permissions],
    );

    const allItems = useMemo(
        () => sections.flatMap((section) => section.items.map((item) => ({ ...item, section: section.label }))),
        [sections],
    );

    const railItems = useMemo(() => curateRailItems(allItems, roleSlug), [allItems, roleSlug]);
    const isDashboard = url.split('?')[0].replace(/\/+$/, '') === '/dashboard' || url.split('?')[0] === '';
    const contextualTabs = useMemo(() => resolveContextualTabs(url, permissions), [url, permissions]);

    const quickActions = [
        { label: 'Open POS', href: '/pos', icon: Monitor, visible: can('pos.access') },
        { label: 'Add product', href: '/dashboard/products/create', icon: Package, visible: can('products.manage') },
        { label: 'Stock transfer', href: '/dashboard/stock/transfers/create', icon: ArrowLeftRight, visible: can('stock.adjust') },
    ].filter((action) => action.visible);

    const badges = {
        '/dashboard/orders/manage': orderNotif.counts.new_orders_count,
        '/dashboard/departments/confirmation': orderNotif.counts.confirmation_pending_count,
    };

    const utilityItem = can('settings.manage')
        ? { label: 'Store Settings', href: '/dashboard/settings', icon: Settings }
        : null;

    useEffect(() => {
        setNavigationOpen(false);
        setSearchOpen(false);
    }, [url]);

    useEffect(() => {
        const onKeyDown = (event) => {
            if ((event.metaKey || event.ctrlKey) && event.key.toLowerCase() === 'k') {
                event.preventDefault();
                setSearchOpen(true);
            }
        };
        window.addEventListener('keydown', onKeyDown);
        return () => window.removeEventListener('keydown', onKeyDown);
    }, []);

    return (
        <PremiumAppShell
            topbar={(
                <FloatingTopbar
                    currentUrl={url}
                    isDashboard={isDashboard}
                    contextualTabs={contextualTabs}
                    onOpenNavigation={() => setNavigationOpen(true)}
                    onOpenSearch={() => setSearchOpen(true)}
                    orderNotif={orderNotif}
                    quickActions={quickActions}
                />
            )}
            sidebar={(
                <>
                    <PermissionAwareRail
                        items={railItems}
                        currentUrl={url}
                        badges={badges}
                        onOpenDrawer={() => setNavigationOpen(true)}
                        utilityItem={utilityItem}
                    />
                    <FullNavigationDrawer
                        sections={sections}
                        currentUrl={url}
                        badges={badges}
                        open={navigationOpen}
                        onOpenChange={setNavigationOpen}
                        agency={Boolean(props.auth?.agency)}
                    />
                </>
            )}
            supportBanner={props.auth?.support ? <SupportBanner support={props.auth.support} /> : null}
            footerLayer={(
                <>
                    <CommandPalette open={searchOpen} onClose={() => setSearchOpen(false)} items={allItems} />
                    <ToastNotification polled={orderNotif.notifications} />
                </>
            )}
        >
            <main className="px-4 py-6 pb-12 sm:px-6 sm:py-8 xl:px-9">
                <div className="mx-auto max-w-[1540px]">
                    {pageHeader && <PageHeader {...pageHeader} />}
                    {children}
                </div>
            </main>
        </PremiumAppShell>
    );
}

function SupportBanner({ support }) {
    return (
        <div className="relative z-20 mx-4 mt-3 rounded-2xl border border-warning/30 bg-warning-soft px-4 py-3 lg:ml-[7.75rem]">
            <div className="flex flex-col gap-2 sm:flex-row sm:items-center sm:justify-between">
                <div className="min-w-0">
                    <p className="flex items-center gap-2 text-xs font-bold uppercase tracking-[0.12em] text-warning"><LifeBuoy className="h-4 w-4" /> Platform support mode</p>
                    <p className="mt-0.5 truncate text-xs text-warning">Store: <strong>{support.storeName}</strong> · Reason: {support.reason}</p>
                </div>
                <button type="button" onClick={() => router.delete('/admin/support')} className="rounded-full bg-card px-3 py-2 text-xs font-semibold text-warning shadow-sm">Exit support mode</button>
            </div>
        </div>
    );
}

function hasNavPermission(permissions, permission) {
    if (! permission) return true;
    if (permissions.includes('*')) return true;
    return Array.isArray(permission)
        ? permission.some((item) => permissions.includes(item))
        : permissions.includes(permission);
}
