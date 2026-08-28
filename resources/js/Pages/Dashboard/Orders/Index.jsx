import { useState } from 'react';
import { Link, router } from '@inertiajs/react';
import { ShoppingCart, Calendar, CheckCircle2, Monitor, Globe, Eye, Printer } from 'lucide-react';
import SaasLayout from '@/Layouts/SaasLayout';
import StatsCard from '@/Components/StatsCard';
import StatusBadge from '@/Components/StatusBadge';
import DataTable from '@/Components/DataTable';
import SearchFilterBar from '@/Components/SearchFilterBar';

/* Unified fulfillment vocabulary — shared by POS and online orders
   (mirrors App\Enums\FulfillmentStatus). */
const STATUS_OPTIONS = [
    { value: 'pending',            label: 'Pending confirmation' },
    { value: 'confirmed',          label: 'Confirmed' },
    { value: 'in_progress',        label: 'Processing' },
    { value: 'ready_for_delivery', label: 'Ready for delivery' },
    { value: 'delivered',          label: 'Delivered' },
    { value: 'completed',          label: 'Completed' },
    { value: 'cancelled',          label: 'Cancelled' },
    { value: 'returned',           label: 'Returned' },
];

const SOURCE_OPTIONS = [
    { value: 'pos',    label: 'POS' },
    { value: 'online', label: 'Online' },
];

const PLATFORM_OPTIONS = [
    { value: 'shopify',     label: 'Shopify' },
    { value: 'woocommerce', label: 'WooCommerce' },
    { value: 'youcan',      label: 'YouCan' },
    { value: 'manual',      label: 'Manual' },
];

export default function Index({ store, orders = { data: [], links: [] }, stats = { today: 0, week: 0, month: 0 }, filters = {}, connections = [] }) {
    const [search, setSearch] = useState(filters.search ?? '');
    const [active, setActive] = useState({
        status: filters.status ?? '',
        source: filters.source ?? '',
        platform: filters.platform ?? '',
        connection: filters.connection ?? '',
    });

    const applyFilters = (next = active, nextSearch = search) => {
        const params = { search: nextSearch, ...next };
        Object.keys(params).forEach((k) => (params[k] === '' || params[k] == null) && delete params[k]);
        router.get('/dashboard/orders', params, { preserveState: true, preserveScroll: true, replace: true });
    };

    const onSearch       = (q) => { setSearch(q); applyFilters(active, q); };
    const onFilterChange = (k, v) => { const n = { ...active, [k]: v }; setActive(n); applyFilters(n); };

    const currency = store?.currency ?? 'MAD';

    const columns = [
        { key: 'reference', label: 'Reference', render: (o) => <span className="font-mono text-xs text-content-muted">{o.reference}</span> },
        {
            key: 'origin',
            label: 'Origin',
            render: (o) => (
                <div>
                    <OriginBadge origin={o.origin} label={o.origin_label} />
                    {o.origin === 'online' && o.connection_label && (
                        <div className="mt-1 text-[11px] text-content-muted truncate max-w-[180px]">
                            {o.connection_label}
                            {o.external_order_number ? ` · #${o.external_order_number}` : ''}
                        </div>
                    )}
                </div>
            ),
        },
        {
            key: 'customer',
            label: 'Customer',
            render: (o) => (
                <div>
                    <div className="text-content">{o.customer_name || <span className="text-content-muted">Walk-in</span>}</div>
                    {o.customer_email && <div className="text-xs text-content-muted">{o.customer_email}</div>}
                </div>
            ),
        },
        { key: 'total',  label: 'Total',  align: 'right', render: (o) => <span className="font-semibold tabular-nums text-content">{fmtMoney(o.total, currency)}</span> },
        { key: 'status', label: 'Status', render: (o) => <StatusBadge type="fulfillment" status={o.status} label={o.status_label} /> },
        { key: 'created_at', label: 'Date', render: (o) => <span className="text-xs text-content-muted">{new Date(o.created_at).toLocaleString()}</span> },
        {
            key: 'actions',
            label: 'Actions',
            align: 'right',
            render: (o) => (
                <div className="flex items-center justify-end gap-2">
                    <Link
                        href={o.view_url}
                        className="inline-flex items-center gap-1.5 px-2.5 py-1.5 text-xs font-medium rounded-lg bg-surface-2 border border-line text-content hover:bg-surface-3 hover:text-content transition"
                    >
                        <Eye className="w-3.5 h-3.5" /> View
                    </Link>
                    <a
                        href={o.receipt_url}
                        target="_blank"
                        rel="noopener"
                        title="Print thermal receipt"
                        className="inline-flex items-center gap-1.5 px-2.5 py-1.5 text-xs font-medium rounded-lg bg-surface-2 border border-line text-content hover:bg-surface-3 hover:text-content transition"
                    >
                        <Printer className="w-3.5 h-3.5" /> Receipt
                    </a>
                </div>
            ),
        },
    ];

    return (
        <SaasLayout pageHeader={{
            title: 'Orders',
            subtitle: 'Every sale — POS and online — in one list',
            breadcrumbs: [{ label: 'Dashboard', href: '/dashboard' }, { label: 'Orders' }],
            actions: (
                <Link
                    href="/pos"
                    className="inline-flex items-center gap-1.5 px-3 py-2 text-sm font-semibold rounded-lg bg-emerald-600 text-white hover:bg-emerald-500 transition"
                >
                    <Monitor className="w-4 h-4" /> Open POS
                </Link>
            ),
        }}>
            <section className="grid grid-cols-1 md:grid-cols-3 gap-4 mb-6">
                <StatsCard label="Today"      value={stats.today} icon={ShoppingCart} color="indigo" />
                <StatsCard label="This week"  value={stats.week}  icon={Calendar}     color="blue" />
                <StatsCard label="This month" value={stats.month} icon={CheckCircle2} color="green" />
            </section>

            <div className="mb-4">
                <SearchFilterBar
                    placeholder="Search reference, customer name, email…"
                    value={search}
                    onSearch={onSearch}
                    filters={[
                        { key: 'source', label: 'Source', options: SOURCE_OPTIONS },
                        { key: 'platform', label: 'Platform', options: PLATFORM_OPTIONS },
                        ...(connections.length > 1
                            ? [{ key: 'connection', label: 'Store', options: connections.map((c) => ({ value: c.id, label: c.label })) }]
                            : []),
                        { key: 'status', label: 'Status', options: STATUS_OPTIONS },
                    ]}
                    activeFilters={active}
                    onFilterChange={onFilterChange}
                />
            </div>

            <DataTable
                columns={columns}
                data={orders.data}
                emptyMessage="No orders match these filters."
                emptyIcon={ShoppingCart}
                footer={
                    orders.links && orders.links.length > 3 ? <Pagination links={orders.links} /> : null
                }
            />
        </SaasLayout>
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

function fmtMoney(value, currency) {
    const n = Number(value) || 0;
    return `${currency} ${n.toLocaleString('en-US', { minimumFractionDigits: 2, maximumFractionDigits: 2 })}`;
}

function Pagination({ links }) {
    return (
        <nav className="flex flex-wrap items-center justify-end gap-1 px-4 py-3">
            {links.map((l, i) => (
                <Link
                    key={i}
                    href={l.url ?? '#'}
                    preserveScroll
                    dangerouslySetInnerHTML={{ __html: l.label }}
                    className={[
                        'min-w-8 px-2.5 py-1 rounded-md text-xs transition',
                        l.active ? 'bg-indigo-600 text-white' : 'text-content-muted hover:bg-surface-3',
                        l.url ? '' : 'opacity-40 pointer-events-none',
                    ].join(' ')}
                />
            ))}
        </nav>
    );
}
