import { useEffect, useMemo, useState } from 'react';
import { Link, router, usePage } from '@inertiajs/react';
import {
    Home, ShoppingCart, Package, Layers,
    Monitor, FileText, Truck,
    Settings, Plug, Users, Building2, ShieldCheck,
    Menu, Search, ChevronRight, CircleDot, Undo2, ClipboardCheck, PackageCheck, Navigation, LifeBuoy,
    ClipboardList, PackageSearch, ArrowLeftRight,
} from 'lucide-react';
import StoreSwitcher from '@/Components/StoreSwitcher';
import NotificationBell from '@/Components/NotificationBell';
import UserDropdown from '@/Components/UserDropdown';
import PageHeader from '@/Components/PageHeader';
import ToastNotification from '@/Components/ToastNotification';
import useOrderNotifications from '@/Hooks/useOrderNotifications';
import ThemeToggle from '@/Components/ThemeToggle';

const NAV_SECTIONS = [
    { label: 'Overview', items: [
        { label: 'Dashboard',  href: '/dashboard',  icon: Home },
    ]},
    { label: 'Commerce', items: [
        { label: 'Products', href: '/dashboard/products', icon: Package, perm: 'products.view' },
    ]},
    // The commercial order record: the full list, and the desk that decides
    // whether a newly-synced order proceeds at all.
    { label: 'Orders', items: [
        { label: 'All orders',        href: '/dashboard/orders/manage',            icon: ShoppingCart,   perm: 'orders.view' },
        { label: 'Confirmation Desk', href: '/dashboard/departments/confirmation', icon: ClipboardCheck, perm: 'orders.confirm' },
    ]},
    // WORKBOARDS — one screen per role, where the hands-on work actually
    // happens (claim an order, do the physical step, move it on). Distinct
    // from Supervisor Queues below, which are read-mostly MONITORING views
    // over the same fulfillment phases, not a place to do the work itself.
    { label: 'Fulfillment Workboards', items: [
        { label: 'Pick & Pack Workbench', href: '/dashboard/departments/packing',  icon: PackageCheck, perm: 'orders.fulfil' },
        { label: 'Delivery Board',        href: '/dashboard/departments/dispatch', icon: Truck,         perm: 'orders.dispatch' },
        { label: 'My deliveries',         href: '/dashboard/my-deliveries',        icon: Navigation,    perm: 'orders.deliver' },
        { label: 'Returns Desk',          href: '/dashboard/orders/returns',       icon: Undo2,         perm: 'orders.inspect' },
    ]},
    // SUPERVISOR QUEUES — single-status monitoring lenses onto the same
    // orders the Workboards above already cover ("what's stuck in picking
    // right now", "what's waiting on a transfer"), not a duplicate place to
    // do the work. Gated on operations.supervise (not orders.fulfil) so a
    // plain picker/packer sees only their Workbench, never these — the
    // underlying /dashboard/operations/* routes stay on orders.fulfil /
    // inventory.transfers.receive exactly as before, so a direct link still
    // works for anyone who already had access; this only changes who sees
    // the SIDEBAR entry.
    { label: 'Supervisor Queues', items: [
        { label: 'Waiting for stock',  href: '/dashboard/operations/waiting-stock',  icon: ClipboardList,  perm: 'operations.supervise' },
        { label: 'Picking Queue',      href: '/dashboard/operations/picking',        icon: PackageSearch,  perm: 'operations.supervise' },
        { label: 'Packing Queue',      href: '/dashboard/operations/packing',        icon: Package,        perm: 'operations.supervise' },
        { label: 'Ready for Dispatch', href: '/dashboard/operations/ready-delivery', icon: PackageCheck,   perm: 'operations.supervise' },
        { label: 'Transfer Receiving', href: '/dashboard/operations/transfers',      icon: ArrowLeftRight, perm: 'operations.supervise' },
    ]},
    { label: 'Inventory', items: [
        { label: 'Warehouses', href: '/dashboard/warehouses',      icon: Building2,      perm: 'warehouses.manage' },
        { label: 'Stock',      href: '/dashboard/stock',           icon: Layers,         perm: 'stock.view' },
        { label: 'Transfers',  href: '/dashboard/stock/transfers', icon: ArrowLeftRight, perm: 'stock.view' },
    ]},
    { label: 'POS System', items: [
        { label: 'POS Terminal',     href: '/pos',                        icon: Monitor,  perm: 'pos.access' },
        { label: 'Factures',         href: '/dashboard/factures',         icon: FileText, perm: 'factures.view' },
        { label: 'Bon de Livraison', href: '/dashboard/bon-de-livraison', icon: Truck,    perm: 'factures.view' },
    ]},
    { label: 'Team', items: [
        { label: 'Team Members', href: '/dashboard/team',  icon: Users,       perm: 'team.manage' },
        { label: 'Roles',        href: '/dashboard/roles', icon: ShieldCheck, perm: 'roles.manage' },
    ]},
    { label: 'Settings', items: [
        { label: 'My Stores',      href: '/dashboard/stores',       icon: Building2, perm: 'stores.manage' },
        { label: 'Store Settings', href: '/dashboard/settings',     icon: Settings,  perm: 'settings.manage' },
        { label: 'Integrations',   href: '/dashboard/integrations', icon: Plug,      perm: 'integrations.manage' },
        { label: 'Delivery providers', href: '/dashboard/delivery-connections', icon: Truck, perm: 'delivery.connections.manage' },
    ]},
];

const MOBILE_NAV = [
    { label: 'Home',     href: '/dashboard',           icon: Home },
    { label: 'Stock',    href: '/dashboard/stock',     icon: Layers,   perm: 'stock.view' },
    { label: 'Factures', href: '/dashboard/factures',  icon: FileText, perm: 'factures.view' },
    { label: 'POS',      href: '/pos',                 icon: Monitor,  perm: 'pos.access' },
];

export default function SaasLayout({ children, pageHeader, title }) {
    const { url, props } = usePage();
    const [mobileOpen, setMobileOpen] = useState(false);
    const permissions = props.auth?.permissions ?? [];
    const can = (perm) => ! perm || permissions.includes('*') || permissions.includes(perm);
    const mobileItems = MOBILE_NAV.filter((item) => can(item.perm));
    const orderNotif = useOrderNotifications();

    useEffect(() => { setMobileOpen(false); }, [url]);

    // Badge counts by nav href — additive, only these two items ever show one.
    const badges = {
        '/dashboard/orders/manage': orderNotif.counts.new_orders_count,
        '/dashboard/departments/confirmation': orderNotif.counts.confirmation_pending_count,
    };

    return (
        <div className="min-h-screen bg-surface text-content font-sans">
            {/* DESKTOP SIDEBAR */}
            <aside className="hidden lg:flex fixed inset-y-0 left-0 w-64 flex-col border-r border-line bg-surface">
                <Sidebar badges={badges} />
            </aside>

            {/* MOBILE DRAWER */}
            {mobileOpen && (
                <div className="lg:hidden fixed inset-0 z-50 flex">
                    <div className="absolute inset-0 bg-black/60 backdrop-blur-sm" onClick={() => setMobileOpen(false)} />
                    <aside className="relative w-72 bg-surface border-r border-line flex flex-col">
                        <Sidebar onNavigate={() => setMobileOpen(false)} badges={badges} />
                    </aside>
                </div>
            )}

            {/* MAIN COLUMN */}
            <div className="lg:ml-64 min-h-screen flex flex-col">
                <TopHeader onToggleMobile={() => setMobileOpen(true)} orderNotif={orderNotif} />
                {props.auth?.support && <SupportBanner support={props.auth.support} />}

                <main className="flex-1 px-4 sm:px-6 lg:px-8 py-6 pb-24 lg:pb-8">
                    <div className="mx-auto max-w-7xl">
                        {pageHeader && <PageHeader {...pageHeader} />}
                        {children}
                    </div>
                </main>
            </div>

            {/* MOBILE BOTTOM NAV */}
            <nav className="lg:hidden fixed bottom-0 inset-x-0 z-30 grid grid-cols-4 bg-surface border-t border-line">
                {mobileItems.map((item) => {
                    const active = isActive(url, item.href);
                    const Icon   = item.icon;
                    return (
                        <Link
                            key={item.href}
                            href={item.href}
                            className={`flex flex-col items-center gap-1 py-2 text-[10px] font-medium transition ${
                                active ? 'text-indigo-600 dark:text-indigo-400' : 'text-content-muted hover:text-content-muted'
                            }`}
                        >
                            <Icon className="w-5 h-5" />
                            {item.label}
                        </Link>
                    );
                })}
            </nav>

            <ToastNotification polled={orderNotif.notifications} />
        </div>
    );
}


function SupportBanner({ support }) {
    const exitSupport = () => router.delete('/admin/support');

    return (
        <div className="sticky top-16 z-20 border-b border-amber-500/30 bg-amber-500/10 px-4 sm:px-6 lg:px-8 py-2.5">
            <div className="mx-auto max-w-7xl flex flex-col gap-2 sm:flex-row sm:items-center sm:justify-between">
                <div className="min-w-0">
                    <div className="flex items-center gap-2 text-xs font-bold uppercase tracking-wider text-amber-800 dark:text-amber-300">
                        <LifeBuoy className="w-4 h-4" /> Platform support mode
                    </div>
                    <div className="mt-0.5 text-xs text-content-muted truncate">
                        Store: <strong className="text-content">{support.storeName}</strong> · Reason: {support.reason}
                    </div>
                </div>
                <button
                    type="button"
                    onClick={exitSupport}
                    className="flex-shrink-0 inline-flex items-center justify-center gap-1.5 px-3 py-1.5 rounded-lg border border-amber-500/30 bg-surface text-xs font-semibold text-amber-800 dark:text-amber-300 hover:bg-surface-2"
                >
                    Exit support mode
                </button>
            </div>
        </div>
    );
}

function Sidebar({ onNavigate, badges = {} }) {
    const { url, props } = usePage();
    const permissions = props.auth?.permissions ?? [];
    const can = (perm) => ! perm || permissions.includes('*') || permissions.includes(perm);

    const sections = NAV_SECTIONS
        .map((s) => ({ ...s, items: s.items.filter((i) => can(i.perm)) }))
        .filter((s) => s.items.length > 0);

    return (
        <>
            <div className="p-4 border-b border-line">
                <div className="flex items-center gap-2 mb-3">
                    <div className="w-7 h-7 rounded-lg bg-gradient-to-br from-indigo-400 to-indigo-700 flex items-center justify-center">
                        <CircleDot className="w-4 h-4 text-white" />
                    </div>
                    <div className="font-bold text-content text-sm tracking-tight">SaaS Commerce</div>
                </div>
                <StoreSwitcher />
                {props.auth?.agency && (
                    <Link href="/agency/clients" className="mt-3 block text-xs text-indigo-600 dark:text-indigo-400 hover:underline">
                        ← Agency workspace
                    </Link>
                )}
            </div>

            <nav className="flex-1 overflow-y-auto px-3 py-4 space-y-5">
                {sections.map((section) => (
                    <div key={section.label}>
                        <div className="px-2 mb-2 text-[10px] font-semibold uppercase tracking-wider text-content-muted">
                            {section.label}
                        </div>
                        <div className="space-y-0.5">
                            {section.items.map((item) => (
                                <SidebarLink
                                    key={item.href}
                                    item={item}
                                    active={isActive(url, item.href)}
                                    onClick={onNavigate}
                                    badge={badges[item.href]}
                                />
                            ))}
                        </div>
                    </div>
                ))}
            </nav>
        </>
    );
}



function SidebarLink({ item, active, onClick, badge }) {
    const Icon = item.icon;
    return (
        <Link
            href={item.href}
            onClick={onClick}
            className={`flex items-center gap-3 px-3 py-2 rounded-lg text-sm transition ${
                active
                    ? 'bg-indigo-500/15 text-indigo-700 dark:text-indigo-300 border border-indigo-500/30'
                    : 'text-content-muted hover:bg-surface-2 hover:text-content border border-transparent'
            }`}
        >
            <Icon className="w-4 h-4 flex-shrink-0" />
            <span className="truncate">{item.label}</span>
            {!!badge && (
                <span className="ml-auto min-w-[18px] h-[18px] px-1 rounded-full bg-indigo-600 text-white text-[10px] font-bold flex items-center justify-center">
                    {badge > 99 ? '99+' : badge}
                </span>
            )}
            {active && !badge && <ChevronRight className="ml-auto w-3.5 h-3.5 text-indigo-600 dark:text-indigo-400" />}
        </Link>
    );
}

function TopHeader({ onToggleMobile, orderNotif }) {
    const { url, props } = usePage();
    const activeStore   = props.auth?.activeStore;

    const crumbs = useMemo(() => buildCrumbsFromUrl(url), [url]);

    return (
        <header className="sticky top-0 z-20 flex items-center gap-3 h-16 px-4 sm:px-6 lg:px-8 border-b border-line bg-surface/80 backdrop-blur-sm">
            <button
                type="button"
                onClick={onToggleMobile}
                aria-label="Open menu"
                className="lg:hidden p-2 text-content-muted hover:text-content"
            >
                <Menu className="w-5 h-5" />
            </button>

            {/* breadcrumbs */}
            <nav aria-label="Breadcrumb" className="hidden md:flex items-center text-xs text-content-muted min-w-0">
                {crumbs.map((c, i) => (
                    <span key={i} className="flex items-center min-w-0">
                        {i > 0 && <ChevronRight className="mx-1 w-3 h-3 text-content-muted/60 flex-shrink-0" />}
                        {c.href ? (
                            <Link href={c.href} className="hover:text-content-muted truncate">{c.label}</Link>
                        ) : (
                            <span className="text-content-muted truncate">{c.label}</span>
                        )}
                    </span>
                ))}
            </nav>

            {/* search */}
            <div className="flex-1 max-w-md mx-auto hidden md:block">
                <button
                    type="button"
                    className="w-full flex items-center gap-2 px-3 py-1.5 rounded-lg bg-surface-2 border border-line text-content-muted hover:border-content-muted/40 transition"
                >
                    <Search className="w-4 h-4" />
                    <span className="text-sm flex-1 text-left">Search…</span>
                    <kbd className="text-[10px] font-medium bg-surface border border-line rounded px-1.5 py-0.5">⌘K</kbd>
                </button>
            </div>

            {/* right */}
            <div className="ml-auto flex items-center gap-2">
                {props.auth?.access?.roleName && (
                    <span className="hidden sm:inline-flex items-center gap-1 px-2 py-0.5 rounded-full text-[10px] font-bold uppercase tracking-wider bg-indigo-500/15 text-indigo-700 dark:text-indigo-300">
                        {props.auth.access.roleName}
                    </span>
                )}
                <span className="hidden sm:inline-flex items-center gap-1.5 px-2 py-1 rounded-full bg-emerald-500/10 border border-emerald-500/20 text-[11px] font-medium text-emerald-700 dark:text-emerald-400">
                    <span className="w-1.5 h-1.5 rounded-full bg-emerald-400 animate-pulse" />
                    Synced
                </span>
                {activeStore && (
                    <span className="hidden md:inline-flex items-center gap-1.5 px-2.5 py-1 rounded-full bg-surface-2 border border-line text-[11px] text-content-muted">
                        {activeStore.name}
                    </span>
                )}
                <ThemeToggle />
                <NotificationBell
                    notifications={orderNotif.notifications}
                    onMarkOne={(orderId) => orderNotif.markSeen('order_detail', orderId)}
                    onMarkAll={() => orderNotif.markSeen('orders_index')}
                />
                <UserDropdown />
            </div>
        </header>
    );
}

function isActive(currentUrl, href) {
    if (! currentUrl || ! href) return false;
    const u = currentUrl.split('?')[0].replace(/\/+$/, '');
    const h = href.replace(/\/+$/, '');
    if (h === '/dashboard') return u === '/dashboard';
    return u === h || u.startsWith(h + '/');
}

function buildCrumbsFromUrl(url) {
    const path = (url ?? '').split('?')[0];
    const segments = path.split('/').filter(Boolean);

    if (segments.length === 0) return [{ label: 'Home', href: '/' }];

    const crumbs = [{ label: 'Dashboard', href: '/dashboard' }];
    let acc = '';
    segments.forEach((seg, i) => {
        if (seg === 'dashboard') return;
        acc += `/${seg}`;
        const label = seg.replace(/-/g, ' ').replace(/\b\w/g, (l) => l.toUpperCase());
        const fullHref = path.startsWith('/dashboard') ? `/dashboard${acc}` : acc;
        crumbs.push({ label, href: i === segments.length - 1 ? null : fullHref });
    });

    return crumbs;
}
