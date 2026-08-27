import { useState } from 'react';
import { Link, usePage } from '@inertiajs/react';
import {
    ArrowRight, CalendarDays, CheckCircle2, CircleDollarSign,
    Clock3, Layers, Monitor, Package, ReceiptText, Store as StoreIcon, Truck,
} from 'lucide-react';
import SaasLayout from '@/Layouts/SaasLayout';
import SoftCard from '@/Components/PremiumDashboard/SoftCard';
import PremiumMetricCard from '@/Components/PremiumDashboard/PremiumMetricCard';
import MiniChartCard from '@/Components/PremiumDashboard/MiniChartCard';
import RecentOrdersCard from '@/Components/PremiumDashboard/RecentOrdersCard';
import StatusPill from '@/Components/PremiumDashboard/StatusPill';
import QuickActionButton from '@/Components/PremiumDashboard/QuickActionButton';
import DashboardSkeleton from '@/Components/PremiumDashboard/DashboardSkeleton';
import EmptyMetricState from '@/Components/PremiumDashboard/EmptyMetricState';

export default function Index({
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
            <SaasLayout>
                <SoftCard className="mx-auto max-w-2xl py-10">
                    <EmptyMetricState icon={StoreIcon} title="No active store" description="Finish onboarding to create your first store, or ask an administrator to add you to one." />
                    <div className="flex justify-center pb-8">
                        <QuickActionButton href="/onboarding">Set up a store</QuickActionButton>
                    </div>
                </SoftCard>
            </SaasLayout>
        );
    }

    if (! stats) {
        return <SaasLayout><DashboardSkeleton /></SaasLayout>;
    }

    const selectedRevenue = range === 'today' ? stats.today_sales : stats.month_revenue;
    const selectedLabel = range === 'today' ? "Today's revenue" : 'Month-to-date revenue';
    const selectedHelper = range === 'today'
        ? `${stats.today_orders} order${Number(stats.today_orders) === 1 ? '' : 's'} today`
        : 'Revenue recorded during the current month';

    return (
        <SaasLayout>
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
        </SaasLayout>
    );
}

function Hero({ userName, storeName, range, onRangeChange, canPos, canViewOrders }) {
    const firstName = String(userName ?? 'there').trim().split(/\s+/)[0];

    return (
        <section className="flex flex-col gap-5 px-1 py-2 sm:flex-row sm:items-end sm:justify-between sm:px-2">
            <div>
                <p className="text-xs font-semibold uppercase tracking-[0.14em] text-[#92978f]">{storeName}</p>
                <h1 className="mt-2 text-3xl font-semibold tracking-[-0.045em] text-[#202420] sm:text-[2.65rem]">
                    Welcome back, <span className="font-normal text-[#626862]">{firstName}</span>
                </h1>
                <p className="mt-2 text-sm text-[#888e87]">Your live commerce and fulfillment overview.</p>
            </div>

            <div className="flex flex-wrap items-center gap-2.5">
                <label className="relative inline-flex items-center gap-2 rounded-full border border-white/90 bg-white px-4 py-2.5 shadow-[0_12px_35px_-26px_rgba(42,56,46,.42)]">
                    <CalendarDays className="h-4 w-4 text-[#697069]" strokeWidth={1.8} />
                    <span className="sr-only">Dashboard date range</span>
                    <select value={range} onChange={(event) => onRangeChange(event.target.value)} className="appearance-none bg-transparent pr-5 text-xs font-semibold text-[#363b36] focus:outline-none">
                        <option value="today">Today · {formatDay(new Date())}</option>
                        <option value="month">This month · {formatMonth(new Date())}</option>
                    </select>
                    <span className="pointer-events-none absolute right-3 text-[10px] text-[#8d938c]">⌄</span>
                </label>

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
                    <h2 className="text-sm font-semibold text-[#252925]">Revenue summary</h2>
                    <p className="mt-0.5 text-xs text-[#92978f]">Current month</p>
                </div>
                <span className="flex h-9 w-9 items-center justify-center rounded-full bg-[#f2f4f0] text-[#737a72]"><ReceiptText className="h-4 w-4" /></span>
            </header>

            <p className="mt-8 text-xs font-medium text-[#92978f]">Total revenue</p>
            <p className="mt-1 text-[2rem] font-semibold tracking-[-0.045em] text-[#202420] tabular-nums">{formatMoney(monthRevenue, currency)}</p>

            <div className="mt-5 rounded-[22px] bg-[#f5f7f3] p-4">
                <div className="flex items-center justify-between gap-3">
                    <span className="text-xs text-[#858b84]">Unpaid invoices</span>
                    <span className="text-sm font-semibold text-[#2c312c] tabular-nums">{formatMoney(unpaidInvoices, currency)}</span>
                </div>
                <div className="mt-4 flex h-16 items-center justify-center rounded-2xl border border-dashed border-[#dfe4dc] text-center text-[11px] text-[#969c95]">
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
                    <span className="flex h-10 w-10 items-center justify-center rounded-2xl bg-[#edf6f0] text-[#118858]"><Layers className="h-5 w-5" /></span>
                    <div><h2 className="text-sm font-semibold text-[#252925]">Inventory health</h2><p className="mt-0.5 text-xs text-[#92978f]">{Number(totalProducts).toLocaleString()} products</p></div>
                </div>
                <Link href="/dashboard/stock" className="text-[#118858]" aria-label="Open stock"><ArrowRight className="h-4 w-4" /></Link>
            </header>

            <div className="mt-4 flex items-center justify-between rounded-2xl bg-[#f6f7f4] px-4 py-3">
                <span className="text-xs text-[#858b84]">Low stock</span>
                <span className={`text-lg font-semibold tabular-nums ${Number(lowStockCount) > 0 ? 'text-amber-600' : 'text-[#118858]'}`}>{Number(lowStockCount).toLocaleString()}</span>
            </div>

            {products.length > 0 && (
                <div className="mt-3 space-y-2">
                    {products.slice(0, 2).map((product) => (
                        <div key={product.id} className="flex items-center gap-2.5 text-xs">
                            <span className="flex h-7 w-7 items-center justify-center rounded-xl bg-[#f1f3ef] text-[#737a72]"><Package className="h-3.5 w-3.5" /></span>
                            <span className="min-w-0 flex-1 truncate text-[#676e67]">{product.name}</span>
                            <span className="font-semibold tabular-nums text-amber-600">{product.stock}</span>
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
                    <span className="flex h-10 w-10 items-center justify-center rounded-2xl bg-[#eff5f4] text-[#32756b]"><Truck className="h-5 w-5" /></span>
                    <div><h2 className="text-sm font-semibold text-[#252925]">Delivery queue</h2><p className="mt-0.5 text-xs text-[#92978f]">{Number(pendingCount).toLocaleString()} pending</p></div>
                </div>
                <Link href="/dashboard/bon-de-livraison" className="text-[#118858]" aria-label="Open deliveries"><ArrowRight className="h-4 w-4" /></Link>
            </header>

            {pendingBons.length === 0 ? (
                <div className="mt-4 flex items-center gap-2 rounded-2xl bg-[#edf6f0] px-4 py-3 text-xs font-medium text-[#118858]"><CheckCircle2 className="h-4 w-4" /> No pending delivery notes</div>
            ) : (
                <div className="mt-4 space-y-2.5">
                    {pendingBons.slice(0, 2).map((bon) => (
                        <div key={bon.id} className="flex items-center justify-between gap-3">
                            <div className="min-w-0"><p className="truncate font-mono text-[11px] text-[#686f68]">{bon.bon_number}</p><p className="mt-0.5 truncate text-[10px] text-[#9aa098]">{bon.customer_name || 'No customer name'}</p></div>
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
                    <span className={`flex h-10 w-10 items-center justify-center rounded-2xl ${session ? 'bg-[#e8f5ed] text-[#118858]' : 'bg-[#f1f3ef] text-[#7c837b]'}`}><Clock3 className="h-5 w-5" /></span>
                    <div><h2 className="text-sm font-semibold text-[#252925]">POS session</h2><p className="mt-0.5 text-xs text-[#92978f]">{session ? `Opened ${session.opened_at}` : 'No active session'}</p></div>
                </div>
                <span className={`mt-1 h-2.5 w-2.5 rounded-full ${session ? 'bg-emerald-500' : 'bg-[#c8ccc6]'}`} />
            </div>
            {session && <p className="mt-4 text-lg font-semibold text-[#252925] tabular-nums">{formatMoney(session.total_sales, currency)}</p>}
            {canPos && <Link href="/pos" className="mt-4 inline-flex items-center gap-1.5 text-xs font-semibold text-[#118858]">{session ? 'Continue selling' : 'Open terminal'} <ArrowRight className="h-3.5 w-3.5" /></Link>}
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
