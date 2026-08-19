import { Link, router, usePage } from '@inertiajs/react';
import {
    DollarSign, TrendingUp, Package, Truck, Monitor, Globe,
    ShoppingCart, Layers, Users, AlertTriangle, CheckCircle2,
    Receipt, ArrowRight, Store as StoreIcon, FileText,
} from 'lucide-react';
import SaasLayout from '@/Layouts/SaasLayout';
import StatsCard from '@/Components/StatsCard';
import StatusBadge from '@/Components/StatusBadge';
import EmptyState from '@/Components/EmptyState';

export default function Index({
    store,
    stats,
    active_session,
    recent_orders = [],
    low_stock_products = [],
    recent_factures = [],
    pending_bons = [],
}) {
    const { auth } = usePage().props;
    const currency = store?.currency ?? 'MAD';
    const greeting = greetingFor(new Date());
    const permissions = auth?.permissions ?? [];
    const can = (permission) => permissions.includes('*') || permissions.includes(permission);
    const canPos = Boolean(auth?.access?.canPos);

    if (! store) {
        return (
            <SaasLayout pageHeader={{ title: 'Dashboard' }}>
                <EmptyState
                    icon={StoreIcon}
                    title="No active store"
                    description="Finish onboarding to create your first store, or ask an admin to add you to one."
                    action={
                        <Link href="/onboarding" className="inline-flex items-center gap-1.5 px-4 py-2 text-sm font-semibold rounded-lg bg-indigo-600 text-white hover:bg-indigo-500">
                            Set up a store
                        </Link>
                    }
                />
            </SaasLayout>
        );
    }

    return (
        <SaasLayout
            pageHeader={{
                title: 'Dashboard',
                subtitle: `${greeting}, ${auth?.user?.name ?? 'there'} · ${store.name}`,
                breadcrumbs: [{ label: 'Dashboard' }],
                actions: canPos ? (
                    <Link
                        href="/pos"
                        className="inline-flex items-center gap-1.5 px-3 py-2 text-sm font-semibold rounded-lg bg-emerald-600 text-white hover:bg-emerald-500 transition"
                    >
                        <Monitor className="w-4 h-4" /> Open POS
                    </Link>
                ) : null,
            }}
        >
            {/* Row 1: stat cards */}
            <section className="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-4 mb-6">
                <StatsCard
                    label="Today's sales"
                    value={fmtMoney(stats.today_sales, currency)}
                    sublabel={`${stats.today_orders} order${stats.today_orders === 1 ? '' : 's'} today`}
                    icon={DollarSign}
                    color="green"
                />
                <StatsCard
                    label="Monthly revenue"
                    value={fmtMoney(stats.month_revenue, currency)}
                    sublabel="This month"
                    icon={TrendingUp}
                    color="blue"
                />
                <Link href="/dashboard/stock" className="block">
                    <StatsCard
                        label="Products"
                        value={fmtNumber(stats.total_products)}
                        sublabel={
                            stats.low_stock_count > 0
                                ? <span className="text-red-600 dark:text-red-400">{stats.low_stock_count} low stock</span>
                                : 'All stocked'
                        }
                        icon={Package}
                        color="indigo"
                    />
                </Link>
                <Link href="/dashboard/bon-de-livraison" className="block">
                    <StatsCard
                        label="Pending deliveries"
                        value={fmtNumber(stats.pending_deliveries)}
                        sublabel={stats.pending_deliveries > 0 ? 'Need attention' : 'All caught up'}
                        icon={Truck}
                        color="yellow"
                    />
                </Link>
            </section>

            {/* Row 2: POS session banner */}
            <SessionBanner session={active_session} currency={currency} />

            {/* Row 3: two columns */}
            <div className="grid grid-cols-1 lg:grid-cols-5 gap-6 mt-6">
                {/* LEFT 60% */}
                <div className="lg:col-span-3 space-y-6">
                    <Card>
                        <CardHeader title="Recent orders" href="/dashboard/orders" />
                        {recent_orders.length === 0 ? (
                            <EmptyState
                                icon={ShoppingCart}
                                title="No orders yet"
                                description="Open the POS to start selling."
                                action={
                                    <Link href="/pos" className="inline-flex items-center gap-1.5 px-3 py-1.5 text-xs font-semibold rounded-lg bg-emerald-600 text-white hover:bg-emerald-500">
                                        <Monitor className="w-3.5 h-3.5" /> Open POS
                                    </Link>
                                }
                            />
                        ) : (
                            <CompactTable>
                                <thead>
                                    <tr>
                                        <Th>Reference</Th>
                                        <Th>Origin</Th>
                                        <Th>Customer</Th>
                                        <Th className="text-right">Amount</Th>
                                        <Th>Method</Th>
                                        <Th className="text-right">When</Th>
                                    </tr>
                                </thead>
                                <tbody>
                                    {recent_orders.map((o) => (
                                        <tr
                                            key={`${o.origin}:${o.id}`}
                                            onClick={() => router.visit(o.view_url)}
                                            className="border-t border-line hover:bg-surface-3 cursor-pointer"
                                        >
                                            <Td className="font-mono text-xs text-content-muted">{o.reference}</Td>
                                            <Td><OriginBadge origin={o.origin} label={o.origin_label} /></Td>
                                            <Td>{o.customer_name || <span className="text-content-muted">Walk-in</span>}</Td>
                                            <Td className="text-right font-semibold text-content tabular-nums">
                                                {fmtMoney(o.total, currency)}
                                            </Td>
                                            <Td>{o.payment_method ? <MethodPill method={o.payment_method} /> : <span className="text-content-muted text-xs">—</span>}</Td>
                                            <Td className="text-right text-xs text-content-muted whitespace-nowrap">
                                                {timeAgo(o.created_at)}
                                            </Td>
                                        </tr>
                                    ))}
                                </tbody>
                            </CompactTable>
                        )}
                    </Card>

                    <Card>
                        <CardHeader title="Recent invoices" href="/dashboard/factures" />
                        {recent_factures.length === 0 ? (
                            <EmptyState icon={FileText} title="No invoices yet" description="Invoices created from POS sales will show up here." />
                        ) : (
                            <CompactTable>
                                <thead>
                                    <tr>
                                        <Th>Invoice #</Th>
                                        <Th>Customer</Th>
                                        <Th className="text-right">Amount</Th>
                                        <Th>Payment</Th>
                                        <Th className="text-right">Date</Th>
                                    </tr>
                                </thead>
                                <tbody>
                                    {recent_factures.map((f) => (
                                        <tr key={f.id} className="border-t border-line hover:bg-surface-3">
                                            <Td className="font-mono text-xs text-content-muted">{f.invoice_number}</Td>
                                            <Td>{f.customer_name || <span className="text-content-muted">—</span>}</Td>
                                            <Td className="text-right font-semibold text-content tabular-nums">
                                                {fmtMoney(f.total_amount, currency)}
                                            </Td>
                                            <Td><StatusBadge type="payment" status={f.payment_status} /></Td>
                                            <Td className="text-right text-xs text-content-muted whitespace-nowrap">{f.invoice_date}</Td>
                                        </tr>
                                    ))}
                                </tbody>
                            </CompactTable>
                        )}
                    </Card>
                </div>

                {/* RIGHT 40% */}
                <div className="lg:col-span-2 space-y-6">
                    <Card>
                        <div className="px-4 py-3 border-b border-line flex items-center justify-between">
                            <div className="flex items-center gap-2">
                                <AlertTriangle className={`w-4 h-4 ${stats.low_stock_count > 0 ? 'text-amber-600 dark:text-amber-400' : 'text-emerald-600 dark:text-emerald-400'}`} />
                                <h2 className="text-sm font-semibold text-content">Low stock alert</h2>
                            </div>
                            <Link href="/dashboard/stock" className="text-xs text-indigo-600 dark:text-indigo-400 hover:text-indigo-700 dark:text-indigo-300">Manage stock →</Link>
                        </div>

                        {stats.low_stock_count === 0 ? (
                            <div className="px-4 py-6 flex items-center gap-3 text-sm text-emerald-700 dark:text-emerald-300">
                                <CheckCircle2 className="w-5 h-5" />
                                All products well stocked.
                            </div>
                        ) : (
                            <ul className="divide-y divide-line">
                                {low_stock_products.map((p) => (
                                    <li key={p.id} className="flex items-center gap-3 px-4 py-3">
                                        {p.image_url ? (
                                            <img src={p.image_url} alt={p.name} loading="lazy" className="w-8 h-8 rounded-md object-cover ring-1 ring-line" />
                                        ) : (
                                            <div className="w-8 h-8 rounded-md bg-surface-3 flex items-center justify-center">
                                                <Package className="w-4 h-4 text-content-muted" />
                                            </div>
                                        )}
                                        <div className="min-w-0 flex-1">
                                            <div className="text-sm text-content truncate">{p.name}</div>
                                            <div className="text-xs text-content-muted font-mono truncate">{p.sku}</div>
                                        </div>
                                        <span className={`text-sm font-bold tabular-nums ${
                                            p.stock <= 5 ? 'text-red-600 dark:text-red-400' : 'text-amber-600 dark:text-amber-400'
                                        }`}>
                                            {p.stock}
                                        </span>
                                    </li>
                                ))}
                            </ul>
                        )}
                    </Card>

                    <Card>
                        <CardHeader title="Pending deliveries" href="/dashboard/bon-de-livraison" />
                        {pending_bons.length === 0 ? (
                            <div className="px-4 py-6 text-sm text-content-muted">No pending deliveries.</div>
                        ) : (
                            <ul className="divide-y divide-line">
                                {pending_bons.map((b) => (
                                    <li key={b.id} className="px-4 py-3">
                                        <div className="flex items-center justify-between gap-2">
                                            <span className="font-mono text-xs text-content-muted">{b.bon_number}</span>
                                            <StatusBadge type="delivery" status={b.status} />
                                        </div>
                                        <div className="mt-1 flex items-center justify-between gap-2 text-xs">
                                            <span className="text-content-muted truncate">{b.customer_name || '—'}</span>
                                            <span className="text-content-muted whitespace-nowrap">{b.expected_delivery_date ?? 'no ETA'}</span>
                                        </div>
                                    </li>
                                ))}
                            </ul>
                        )}
                    </Card>

                    <Card>
                        <div className="px-4 py-3 border-b border-line">
                            <h2 className="text-sm font-semibold text-content">Quick actions</h2>
                        </div>
                        <div className="grid grid-cols-2 gap-2 p-3">
                            {canPos && (
                                <QuickAction href="/pos" icon={Monitor} label="New POS sale" tone="emerald" />
                            )}
                            {can('orders.view') && (
                                <QuickAction href="/dashboard/orders" icon={ShoppingCart} label="View orders" tone="blue" />
                            )}
                            {can('stock.view') && (
                                <QuickAction href="/dashboard/stock" icon={Layers} label="Manage stock" tone="indigo" />
                            )}
                            {can('team.manage') && (
                                <QuickAction href="/dashboard/team" icon={Users} label="Team" tone="slate" />
                            )}
                        </div>
                    </Card>
                </div>
            </div>

            {/* Unpaid invoices bottom banner */}
            {stats.unpaid_invoices > 0 && (
                <div className="mt-6 flex flex-col sm:flex-row sm:items-center justify-between gap-3 px-4 py-3 rounded-xl bg-amber-500/10 border border-amber-500/30">
                    <div className="flex items-start gap-3">
                        <Receipt className="w-5 h-5 text-amber-700 dark:text-amber-300 flex-shrink-0 mt-0.5" />
                        <div className="text-sm">
                            <div className="text-amber-800 dark:text-amber-200 font-medium">
                                {fmtMoney(stats.unpaid_invoices, currency)} in unpaid invoices
                            </div>
                            <div className="text-xs text-amber-700 dark:text-amber-300/80 mt-0.5">Customers haven't settled these invoices yet.</div>
                        </div>
                    </div>
                    <Link
                        href="/dashboard/factures?payment_status=unpaid"
                        className="inline-flex items-center gap-1.5 px-3 py-1.5 text-xs font-semibold rounded-lg bg-amber-500/20 text-amber-800 dark:text-amber-200 hover:bg-amber-500/30 transition"
                    >
                        View invoices <ArrowRight className="w-3.5 h-3.5" />
                    </Link>
                </div>
            )}
        </SaasLayout>
    );
}

/* ---------- Banner ---------- */

function SessionBanner({ session, currency }) {
    if (! session) {
        return (
            <div className="flex flex-col sm:flex-row sm:items-center justify-between gap-3 px-4 py-3 rounded-xl bg-surface-2 border border-line">
                <div className="flex items-start gap-3">
                    <Monitor className="w-5 h-5 text-content-muted flex-shrink-0 mt-0.5" />
                    <div className="text-sm">
                        <div className="text-content font-medium">No active POS session</div>
                        <div className="text-xs text-content-muted mt-0.5">Open a session to start ringing up sales.</div>
                    </div>
                </div>
                <Link
                    href="/pos"
                    className="inline-flex items-center gap-1.5 px-3 py-2 text-sm font-semibold rounded-lg bg-emerald-600 text-white hover:bg-emerald-500 transition"
                >
                    Open POS Terminal
                </Link>
            </div>
        );
    }

    return (
        <div className="flex flex-col sm:flex-row sm:items-center justify-between gap-3 px-4 py-3 rounded-xl bg-emerald-500/10 border border-emerald-500/30">
            <div className="flex items-start gap-3">
                <span className="relative flex h-3 w-3 mt-1.5 flex-shrink-0">
                    <span className="absolute inline-flex h-full w-full rounded-full bg-emerald-400 opacity-75 animate-ping" />
                    <span className="relative inline-flex rounded-full h-3 w-3 bg-emerald-500" />
                </span>
                <div className="text-sm">
                    <div className="text-emerald-800 dark:text-emerald-200 font-medium">POS session active</div>
                    <div className="text-xs text-emerald-700 dark:text-emerald-300/80 mt-0.5">
                        Opened {session.opened_at} · Sales {fmtMoney(session.total_sales, currency)}
                    </div>
                </div>
            </div>
            <div className="flex items-center gap-2">
                <Link
                    href="/pos"
                    className="inline-flex items-center gap-1.5 px-3 py-1.5 text-xs font-semibold rounded-lg bg-emerald-600 text-white hover:bg-emerald-500 transition"
                >
                    Go to POS <ArrowRight className="w-3.5 h-3.5" />
                </Link>
            </div>
        </div>
    );
}

/* ---------- Small building blocks ---------- */

function Card({ children }) {
    return <div className="bg-surface-2 border border-line rounded-xl overflow-hidden">{children}</div>;
}

function CardHeader({ title, href }) {
    return (
        <div className="px-4 py-3 border-b border-line flex items-center justify-between">
            <h2 className="text-sm font-semibold text-content">{title}</h2>
            {href && <Link href={href} className="text-xs text-indigo-600 dark:text-indigo-400 hover:text-indigo-700 dark:text-indigo-300">View all →</Link>}
        </div>
    );
}

function CompactTable({ children }) {
    return (
        <div className="overflow-x-auto">
            <table className="w-full text-sm">{children}</table>
        </div>
    );
}

function Th({ children, className = '' }) {
    return <th className={`px-4 py-2.5 text-left text-[10px] uppercase tracking-wider text-content-muted bg-surface-3 ${className}`}>{children}</th>;
}

function Td({ children, className = '' }) {
    return <td className={`px-4 py-2.5 text-content-muted ${className}`}>{children}</td>;
}

function QuickAction({ href, icon: Icon, label, tone }) {
    const tones = {
        emerald: 'bg-emerald-500/10 hover:bg-emerald-500/20 text-emerald-700 dark:text-emerald-300 border-emerald-500/20',
        blue:    'bg-blue-500/10 hover:bg-blue-500/20 text-blue-700 dark:text-blue-300 border-blue-500/20',
        indigo:  'bg-indigo-500/10 hover:bg-indigo-500/20 text-indigo-700 dark:text-indigo-300 border-indigo-500/20',
        slate:   'bg-slate-500/10 hover:bg-slate-500/20 text-content-muted border-slate-500/20',
    };
    return (
        <Link
            href={href}
            className={`flex items-center gap-2 px-3 py-2.5 text-xs font-medium rounded-lg border transition ${tones[tone] ?? tones.indigo}`}
        >
            <Icon className="w-4 h-4 flex-shrink-0" />
            <span className="truncate">{label}</span>
        </Link>
    );
}

function OriginBadge({ origin, label }) {
    const tone = origin === 'online'
        ? 'bg-blue-500/15 text-blue-700 dark:text-blue-300'
        : 'bg-indigo-500/15 text-indigo-700 dark:text-indigo-300';
    return (
        <span className={`inline-flex items-center gap-1 px-2 py-0.5 rounded-full text-[10px] font-medium uppercase tracking-wider ${tone}`}>
            {origin === 'online' ? <Globe className="w-3 h-3" /> : <Monitor className="w-3 h-3" />}
            {label ?? origin}
        </span>
    );
}

function MethodPill({ method }) {
    const t = {
        cash:   'bg-emerald-500/15 text-emerald-700 dark:text-emerald-300',
        card:   'bg-blue-500/15 text-blue-700 dark:text-blue-300',
        check:  'bg-slate-500/20 text-content-muted',
        mobile: 'bg-purple-500/15 text-purple-700 dark:text-purple-300',
        mixed:  'bg-indigo-500/15 text-indigo-700 dark:text-indigo-300',
    }[method] ?? 'bg-slate-700/40 text-content-muted';
    return (
        <span className={`inline-flex px-2 py-0.5 rounded-md text-[10px] font-medium uppercase tracking-wider ${t}`}>
            {method ?? '—'}
        </span>
    );
}

/* ---------- Formatting helpers ---------- */

function fmtMoney(value, currency) {
    const n = Number(value) || 0;
    const formatted = n.toLocaleString('en-US', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
    return `${currency} ${formatted}`;
}

function fmtNumber(value) {
    return (Number(value) || 0).toLocaleString('en-US');
}

function greetingFor(date) {
    const h = date.getHours();
    if (h < 12) return 'Good morning';
    if (h < 18) return 'Good afternoon';
    return 'Good evening';
}

function timeAgo(iso) {
    if (! iso) return '—';
    const d = new Date(iso);
    const diff = (Date.now() - d.getTime()) / 1000;

    if (diff < 60)    return 'just now';
    if (diff < 3600)  return `${Math.floor(diff / 60)}m ago`;
    if (diff < 86400) return `${Math.floor(diff / 3600)}h ago`;
    if (diff < 172800) return 'yesterday';
    if (diff < 604800) return `${Math.floor(diff / 86400)}d ago`;

    try { return d.toLocaleDateString(); } catch { return iso; }
}
