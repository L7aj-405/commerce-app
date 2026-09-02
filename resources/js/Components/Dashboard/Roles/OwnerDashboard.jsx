import { useState } from 'react';
import { Link, usePage } from '@inertiajs/react';
import {
    ArrowRight, CalendarDays, CheckCircle2, CircleDollarSign,
    Clock3, Layers, Monitor, Package, ReceiptText, Store as StoreIcon, Truck,
} from 'lucide-react';
import SoftCard from '@/Components/PremiumDashboard/SoftCard';
import PremiumMetricCard from '@/Components/PremiumDashboard/PremiumMetricCard';
import MiniChartCard from '@/Components/PremiumDashboard/MiniChartCard';
import RecentOrdersCard from '@/Components/PremiumDashboard/RecentOrdersCard';
import StatusPill from '@/Components/PremiumDashboard/StatusPill';
import QuickActionButton from '@/Components/PremiumDashboard/QuickActionButton';
import DashboardSkeleton from '@/Components/PremiumDashboard/DashboardSkeleton';
import EmptyMetricState from '@/Components/PremiumDashboard/EmptyMetricState';
import Select from '@/Components/Select';

/**
 * The business-overview dashboard — byte-for-byte the same content that used
 * to be the whole of Pages/Dashboard/Index.jsx, just extracted into its own
 * component so the page can route to a different one per role. Zero visual
 * change for owners/admins. `team_activity` is only ever present when the
 * backend decided the viewer may see it (team.manage/operations.supervise/
 * orders.manage) — never fetched or rendered otherwise.
 */
export default function OwnerDashboard({
    store,
    stats,
    active_session,
    recent_orders = [],
    low_stock_products = [],
    pending_bons = [],
}) {
    const { auth } = usePage().props;
    const [range, setRange] = useState('today');
    const currency = store?.currency ?? 'MAD';
    const permissions = auth?.permissions ?? [];
    const can = (permission) => permissions.includes('*') || permissions.includes(permission);
    const canPos = Boolean(auth?.access?.canPos);

    if (! store) {
        return (
            <SoftCard className="mx-auto max-w-2xl py-10">
                <EmptyMetricState icon={StoreIcon} title="No active store" description="Finish onboarding to create your first store, or ask an administrator to add you to one." />
                <div className="flex justify-center pb-8">
                    <QuickActionButton href="/onboarding">Set up a store</QuickActionButton>
                </div>
            </SoftCard>
        );
    }

    if (! stats) {
        return <DashboardSkeleton />;
    }

    const selectedRevenue = range === 'today' ? stats.today_sales : stats.month_revenue;
    const selectedLabel = range === 'today' ? "Today's revenue" : 'Month-to-date revenue';
    const selectedHelper = range === 'today'
        ? `${stats.today_orders} order${Number(stats.today_orders) === 1 ? '' : 's'} today`
        : 'Revenue recorded during the current month';

    return (
        <div className="page-enter space-y-6">
            <Hero
                userName={auth?.user?.name}
                storeName={store.name}
                range={range}
                onRangeChange={setRange}
                canPos={canPos}
                canViewOrders={can('orders.view')}
            />

            <div className="grid gap-5 xl:grid-cols-[290px_minmax(420px,1fr)_310px]">
                <PremiumMetricCard
                    label={selectedLabel}
                    value={selectedRevenue}
                    helper={selectedHelper}
                    secondaryLabel="Monthly revenue"
                    secondaryValue={formatMoney(stats.month_revenue, currency)}
                    currency={currency}
                    icon={CircleDollarSign}
                />

                <MiniChartCard
                    title="Sales performance"
                    subtitle={range === 'today' ? 'Orders and revenue by time of day' : 'Daily revenue during this month'}
                    value={formatMoney(selectedRevenue, currency)}
                    data={[]}
                    className="min-h-[348px]"
                />

                <RevenueSummary
                    currency={currency}
                    monthRevenue={stats.month_revenue}
                    unpaidInvoices={stats.unpaid_invoices}
                    canPos={canPos}
                    canViewOrders={can('orders.view')}
                />
            </div>

            <div className="grid gap-5 xl:grid-cols-[minmax(0,1fr)_310px]">
                <RecentOrdersCard orders={recent_orders} currency={currency} />

                <div className="space-y-5">
                    <InventoryHealth
                        totalProducts={stats.total_products}
                        lowStockCount={stats.low_stock_count}
                        products={low_stock_products}
                    />
                    <DeliveryQueue pendingCount={stats.pending_deliveries} pendingBons={pending_bons} />
                    <PosSession session={active_session} currency={currency} canPos={canPos} />
                </div>
            </div>
        </div>
    );
}

function Hero({ userName, storeName, range, onRangeChange, canPos, canViewOrders }) {
    const firstName = String(userName ?? 'there').trim().split(/\s+/)[0];

    return (
        <section className="flex flex-col gap-5 px-1 py-2 sm:flex-row sm:items-end sm:justify-between sm:px-2">
            <div>
                <p className="text-xs font-semibold uppercase tracking-[0.14em] text-content-muted">{storeName}</p>
                <h1 className="mt-2 text-3xl font-semibold tracking-[-0.045em] text-content sm:text-[2.65rem]">
                    Welcome back, <span className="font-normal text-content-muted">{firstName}</span>
                </h1>
                <p className="mt-2 text-sm text-content-muted">Your live commerce and fulfillment overview.</p>
            </div>

            <div className="flex flex-wrap items-center gap-2.5">
                <Select
                    value={range}
                    onChange={onRangeChange}
                    icon={CalendarDays}
                    ariaLabel="Dashboard date range"
                    className="w-auto"
                    buttonClassName="inline-flex items-center gap-2 rounded-full border border-border bg-card px-4 py-2.5 text-xs font-semibold text-content shadow-premium transition hover:border-primary/30 focus:outline-none focus-visible:ring-2 focus-visible:ring-primary/35"
                    menuClassName="whitespace-nowrap"
                    options={[
                        { value: 'today', label: `Today · ${formatDay(new Date())}` },
                        { value: 'month', label: `This month · ${formatMonth(new Date())}` },
                    ]}
                />

                {canPos ? (
                    <QuickActionButton href="/pos" icon={Monitor}>New POS sale</QuickActionButton>
                ) : canViewOrders ? (
                    <QuickActionButton href="/dashboard/orders/manage">View orders</QuickActionButton>
                ) : null}
            </div>
        </section>
    );
}

function RevenueSummary({ currency, monthRevenue, unpaidInvoices, canPos, canViewOrders }) {
    return (
        <SoftCard className="flex min-h-[348px] flex-col overflow-hidden p-6">
            <header className="flex items-start justify-between gap-3">
                <div>
                    <h2 className="text-sm font-semibold text-content">Revenue summary</h2>
                    <p className="mt-0.5 text-xs text-content-muted">Current month</p>
                </div>
                <span className="flex h-9 w-9 items-center justify-center rounded-full bg-surface-soft text-content-muted"><ReceiptText className="h-4 w-4" /></span>
            </header>

            <p className="mt-8 text-xs font-medium text-content-muted">Total revenue</p>
            <p className="mt-1 text-[2rem] font-semibold tracking-[-0.045em] text-content tabular-nums">{formatMoney(monthRevenue, currency)}</p>

            <div className="mt-5 rounded-[22px] bg-surface-soft p-4">
                <div className="flex items-center justify-between gap-3">
                    <span className="text-xs text-content-muted">Unpaid invoices</span>
                    <span className="text-sm font-semibold text-content tabular-nums">{formatMoney(unpaidInvoices, currency)}</span>
                </div>
                <div className="mt-4 flex h-16 items-center justify-center rounded-2xl border border-dashed border-border text-center text-[11px] text-content-muted">
                    No revenue trend series is exposed yet
                </div>
            </div>

            <div className="mt-auto grid grid-cols-2 gap-2 pt-5">
                {canViewOrders && <QuickActionButton href="/dashboard/orders/manage" variant="secondary" className="px-3 py-2 text-xs">Orders</QuickActionButton>}
                {canPos && <QuickActionButton href="/pos" className="px-3 py-2 text-xs">Open POS</QuickActionButton>}
            </div>
        </SoftCard>
    );
}

function InventoryHealth({ totalProducts, lowStockCount, products }) {
    return (
        <SoftCard className="p-5">
            <header className="flex items-center justify-between gap-3">
                <div className="flex items-center gap-3">
                    <span className="flex h-10 w-10 items-center justify-center rounded-2xl bg-primary-soft text-primary"><Layers className="h-5 w-5" /></span>
                    <div><h2 className="text-sm font-semibold text-content">Inventory health</h2><p className="mt-0.5 text-xs text-content-muted">{Number(totalProducts).toLocaleString()} products</p></div>
                </div>
                <Link href="/dashboard/stock" className="text-primary" aria-label="Open stock"><ArrowRight className="h-4 w-4" /></Link>
            </header>

            <div className="mt-4 flex items-center justify-between rounded-2xl bg-surface-soft px-4 py-3">
                <span className="text-xs text-content-muted">Low stock</span>
                <span className={`text-lg font-semibold tabular-nums ${Number(lowStockCount) > 0 ? 'text-warning' : 'text-primary'}`}>{Number(lowStockCount).toLocaleString()}</span>
            </div>

            {products.length > 0 && (
                <div className="mt-3 space-y-2">
                    {products.slice(0, 2).map((product) => (
                        <div key={product.id} className="flex items-center gap-2.5 text-xs">
                            <span className="flex h-7 w-7 items-center justify-center rounded-xl bg-surface-soft text-content-muted"><Package className="h-3.5 w-3.5" /></span>
                            <span className="min-w-0 flex-1 truncate text-content-muted">{product.name}</span>
                            <span className="font-semibold tabular-nums text-warning">{product.stock}</span>
                        </div>
                    ))}
                </div>
            )}
        </SoftCard>
    );
}

function DeliveryQueue({ pendingCount, pendingBons }) {
    return (
        <SoftCard className="p-5">
            <header className="flex items-center justify-between gap-3">
                <div className="flex items-center gap-3">
                    <span className="flex h-10 w-10 items-center justify-center rounded-2xl bg-primary-soft text-primary"><Truck className="h-5 w-5" /></span>
                    <div><h2 className="text-sm font-semibold text-content">Delivery queue</h2><p className="mt-0.5 text-xs text-content-muted">{Number(pendingCount).toLocaleString()} pending</p></div>
                </div>
                <Link href="/dashboard/bon-de-livraison" className="text-primary" aria-label="Open deliveries"><ArrowRight className="h-4 w-4" /></Link>
            </header>

            {pendingBons.length === 0 ? (
                <div className="mt-4 flex items-center gap-2 rounded-2xl bg-primary-soft px-4 py-3 text-xs font-medium text-primary"><CheckCircle2 className="h-4 w-4" /> No pending delivery notes</div>
            ) : (
                <div className="mt-4 space-y-2.5">
                    {pendingBons.slice(0, 2).map((bon) => (
                        <div key={bon.id} className="flex items-center justify-between gap-3">
                            <div className="min-w-0"><p className="truncate font-mono text-[11px] text-content-muted">{bon.bon_number}</p><p className="mt-0.5 truncate text-[10px] text-content-muted">{bon.customer_name || 'No customer name'}</p></div>
                            <StatusPill status={bon.status} />
                        </div>
                    ))}
                </div>
            )}
        </SoftCard>
    );
}

function PosSession({ session, currency, canPos }) {
    return (
        <SoftCard className="p-5">
            <div className="flex items-start justify-between gap-3">
                <div className="flex items-center gap-3">
                    <span className={`flex h-10 w-10 items-center justify-center rounded-2xl ${session ? 'bg-primary-soft text-primary' : 'bg-surface-soft text-content-muted'}`}><Clock3 className="h-5 w-5" /></span>
                    <div><h2 className="text-sm font-semibold text-content">POS session</h2><p className="mt-0.5 text-xs text-content-muted">{session ? `Opened ${session.opened_at}` : 'No active session'}</p></div>
                </div>
                <span className={`mt-1 h-2.5 w-2.5 rounded-full ${session ? 'bg-success' : 'bg-content-muted/30'}`} />
            </div>
            {session && <p className="mt-4 text-lg font-semibold text-content tabular-nums">{formatMoney(session.total_sales, currency)}</p>}
            {canPos && <Link href="/pos" className="mt-4 inline-flex items-center gap-1.5 text-xs font-semibold text-primary">{session ? 'Continue selling' : 'Open terminal'} <ArrowRight className="h-3.5 w-3.5" /></Link>}
        </SoftCard>
    );
}

function formatMoney(value, currency) {
    return `${currency} ${(Number(value) || 0).toLocaleString('en-US', { minimumFractionDigits: 2, maximumFractionDigits: 2 })}`;
}

function formatDay(date) {
    return date.toLocaleDateString([], { month: 'short', day: 'numeric' });
}

function formatMonth(date) {
    return date.toLocaleDateString([], { month: 'long', year: 'numeric' });
}
