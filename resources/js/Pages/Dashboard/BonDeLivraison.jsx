import { useState } from 'react';
import { Link, router } from '@inertiajs/react';
import { Clock, Truck, PackageCheck, Plus } from 'lucide-react';
import SaasLayout from '@/Layouts/SaasLayout';
import StatsCard from '@/Components/StatsCard';
import StatusBadge from '@/Components/StatusBadge';
import DataTable from '@/Components/DataTable';
import SearchFilterBar from '@/Components/SearchFilterBar';

const STATUS_OPTIONS = [
    { value: 'pending',   label: 'Pending' },
    { value: 'preparing', label: 'Preparing' },
    { value: 'ready',     label: 'Ready' },
    { value: 'shipped',   label: 'Shipped' },
    { value: 'delivered', label: 'Delivered' },
    { value: 'cancelled', label: 'Cancelled' },
];

const QUICK_STATUSES = STATUS_OPTIONS.map((o) => o.value);

export default function BonDeLivraison({ bons, stats, filters = {} }) {
    const [search, setSearch] = useState(filters.search ?? '');
    const [active, setActive] = useState({ status: filters.status ?? '' });

    const applyFilters = (next = active, nextSearch = search) => {
        const params = { search: nextSearch, ...next };
        Object.keys(params).forEach((k) => (params[k] === '' || params[k] == null) && delete params[k]);
        router.get('/dashboard/bon-de-livraison', params, { preserveState: true, preserveScroll: true, replace: true });
    };

    const onSearch       = (q) => { setSearch(q); applyFilters(active, q); };
    const onFilterChange = (k, v) => { const n = { ...active, [k]: v }; setActive(n); applyFilters(n); };

    const updateStatus = (bon, newStatus) => {
        if (newStatus === bon.status) return;
        router.patch(`/dashboard/bon-de-livraison/${bon.id}/status`, { status: newStatus }, { preserveScroll: true });
    };

    const columns = [
        {
            key: 'bon_number',
            label: 'Bon',
            render: (b) => <span className="font-mono text-xs text-content-muted">{b.bon_number}</span>,
        },
        {
            key: 'customer',
            label: 'Customer',
            render: (b) => (
                <div>
                    <div className="text-content font-medium">{b.customer_name}</div>
                    {b.customer_phone && <div className="text-xs text-content-muted">{b.customer_phone}</div>}
                </div>
            ),
        },
        { key: 'status', label: 'Status', render: (b) => <StatusBadge type="delivery" status={b.status} /> },
        {
            key: 'items',
            label: 'Items',
            render: (b) => {
                const delivered = Number(b.items_delivered) || 0;
                const total     = Number(b.total_items) || 0;
                const pct       = total > 0 ? Math.min(100, Math.round((delivered / total) * 100)) : 0;
                return (
                    <div>
                        <div className="text-xs text-content-muted tabular-nums">{delivered} / {total}</div>
                        <div className="mt-1 w-24 h-1.5 bg-surface-3 rounded-full overflow-hidden">
                            <div className="h-full bg-emerald-500" style={{ width: `${pct}%` }} />
                        </div>
                    </div>
                );
            },
        },
        { key: 'tracking_number',        label: 'Tracking',  render: (b) => <span className="font-mono text-xs text-content-muted">{b.tracking_number ?? '—'}</span> },
        { key: 'expected_delivery_date', label: 'Expected',  render: (b) => <span className="text-content-muted">{b.expected_delivery_date ?? '—'}</span> },
        {
            key: 'actions',
            label: '',
            align: 'right',
            render: (b) => (
                <select
                    value={b.status}
                    onClick={(e) => e.stopPropagation()}
                    onChange={(e) => updateStatus(b, e.target.value)}
                    className="text-xs px-2 py-1 rounded-md bg-surface border border-line text-content-muted focus:outline-none focus:border-indigo-500 focus:ring-1 focus:ring-indigo-500"
                >
                    {QUICK_STATUSES.map((s) => <option key={s} value={s}>{s}</option>)}
                </select>
            ),
        },
    ];

    return (
        <SaasLayout
            pageHeader={{
                title: 'Bon de Livraison',
                subtitle: 'Delivery notes and shipment tracking',
                breadcrumbs: [{ label: 'Dashboard', href: '/dashboard' }, { label: 'Bon de Livraison' }],
                actions: (
                    <Link
                        href="/dashboard/bon-de-livraison/create"
                        className="inline-flex items-center gap-1.5 px-3 py-2 text-sm font-medium rounded-lg bg-indigo-600 text-white hover:bg-indigo-500 transition"
                    >
                        <Plus className="w-4 h-4" />
                        New delivery
                    </Link>
                ),
            }}
        >
            <section className="grid grid-cols-1 md:grid-cols-3 gap-4 mb-6">
                <StatsCard label="Pending"   value={stats.pending}   icon={Clock}        color="yellow" />
                <StatsCard label="Shipped"   value={stats.shipped}   icon={Truck}        color="blue" />
                <StatsCard label="Delivered" value={stats.delivered} icon={PackageCheck} color="green" />
            </section>

            <div className="mb-4">
                <SearchFilterBar
                    placeholder="Search bon #, customer, tracking #…"
                    value={search}
                    onSearch={onSearch}
                    filters={[{ key: 'status', label: 'Status', options: STATUS_OPTIONS }]}
                    activeFilters={active}
                    onFilterChange={onFilterChange}
                />
            </div>

            <DataTable
                columns={columns}
                data={bons.data}
                emptyMessage="No delivery notes match your filters."
                emptyIcon={Truck}
                footer={
                    bons.links && bons.links.length > 3 ? (
                        <Pagination links={bons.links} />
                    ) : null
                }
            />
        </SaasLayout>
    );
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
