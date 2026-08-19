import { useState } from 'react';
import { Link, router } from '@inertiajs/react';
import { FileText, CheckCircle2, AlertCircle, Clock, DollarSign, Download, Eye, Plus } from 'lucide-react';
import SaasLayout from '@/Layouts/SaasLayout';
import StatsCard from '@/Components/StatsCard';
import StatusBadge from '@/Components/StatusBadge';
import DataTable from '@/Components/DataTable';
import SearchFilterBar from '@/Components/SearchFilterBar';

const STATUS_OPTIONS = [
    { value: 'draft', label: 'Draft' },
    { value: 'sent',  label: 'Sent' },
    { value: 'paid',  label: 'Paid' },
    { value: 'overdue',   label: 'Overdue' },
    { value: 'cancelled', label: 'Cancelled' },
];

const PAYMENT_OPTIONS = [
    { value: 'unpaid',  label: 'Unpaid' },
    { value: 'partial', label: 'Partial' },
    { value: 'paid',    label: 'Paid' },
];

export default function Factures({ factures, stats, filters = {} }) {
    const [search, setSearch] = useState(filters.search ?? '');
    const [active, setActive] = useState({
        status:         filters.status ?? '',
        payment_status: filters.payment_status ?? '',
    });

    const applyFilters = (next = active, nextSearch = search) => {
        const params = { search: nextSearch, ...next };
        Object.keys(params).forEach((k) => (params[k] === '' || params[k] == null) && delete params[k]);
        router.get('/dashboard/factures', params, { preserveState: true, preserveScroll: true, replace: true });
    };

    const onSearch     = (q) => { setSearch(q); applyFilters(active, q); };
    const onFilterChange = (k, v) => { const n = { ...active, [k]: v }; setActive(n); applyFilters(n); };

    const columns = [
        {
            key: 'invoice_number',
            label: 'Invoice',
            render: (f) => <span className="font-mono text-xs text-content-muted">{f.invoice_number}</span>,
        },
        {
            key: 'customer',
            label: 'Customer',
            render: (f) => (
                <div>
                    <div className="text-content font-medium">{f.customer_name}</div>
                    {f.customer_email && <div className="text-xs text-content-muted">{f.customer_email}</div>}
                </div>
            ),
        },
        {
            key: 'total_amount',
            label: 'Amount',
            align: 'right',
            render: (f) => <span className="font-semibold tabular-nums text-content">${Number(f.total_amount).toFixed(2)}</span>,
        },
        { key: 'status',         label: 'Status',  render: (f) => <StatusBadge type="invoice" status={f.status} /> },
        { key: 'payment_status', label: 'Payment', render: (f) => <StatusBadge type="payment" status={f.payment_status} /> },
        { key: 'invoice_date',   label: 'Date',    render: (f) => <span className="text-content-muted">{f.invoice_date}</span> },
        {
            key: 'actions',
            label: '',
            align: 'right',
            render: (f) => (
                <div className="inline-flex items-center gap-1">
                    <Link href={`/dashboard/factures/${f.id}`} className="p-1.5 rounded-md text-content-muted hover:bg-surface-3 hover:text-content" aria-label="View">
                        <Eye className="w-4 h-4" />
                    </Link>
                    <a href={`/dashboard/factures/${f.id}/download`} className="p-1.5 rounded-md text-content-muted hover:bg-surface-3 hover:text-content" aria-label="Download">
                        <Download className="w-4 h-4" />
                    </a>
                </div>
            ),
        },
    ];

    return (
        <SaasLayout
            pageHeader={{
                title: 'Factures',
                subtitle: 'Invoices and payment status across your stores',
                breadcrumbs: [{ label: 'Dashboard', href: '/dashboard' }, { label: 'Factures' }],
                actions: (
                    <Link
                        href="/dashboard/factures/create"
                        className="inline-flex items-center gap-1.5 px-3 py-2 text-sm font-medium rounded-lg bg-indigo-600 text-white hover:bg-indigo-500 transition"
                    >
                        <Plus className="w-4 h-4" />
                        New invoice
                    </Link>
                ),
            }}
        >
            <section className="grid grid-cols-2 md:grid-cols-3 lg:grid-cols-5 gap-4 mb-6">
                <StatsCard label="Total"    value={stats.total}   icon={FileText}     color="indigo" />
                <StatsCard label="Paid"     value={stats.paid}    icon={CheckCircle2} color="green" />
                <StatsCard label="Unpaid"   value={stats.unpaid}  icon={Clock}        color="yellow" />
                <StatsCard label="Overdue"  value={stats.overdue} icon={AlertCircle}  color="red" />
                <StatsCard label="Revenue"  value={`$${Number(stats.total_revenue).toFixed(2)}`} icon={DollarSign} color="indigo" />
            </section>

            <div className="mb-4">
                <SearchFilterBar
                    placeholder="Search invoice #, customer name, email…"
                    value={search}
                    onSearch={onSearch}
                    filters={[
                        { key: 'status',         label: 'Status',  options: STATUS_OPTIONS },
                        { key: 'payment_status', label: 'Payment', options: PAYMENT_OPTIONS },
                    ]}
                    activeFilters={active}
                    onFilterChange={onFilterChange}
                />
            </div>

            <DataTable
                columns={columns}
                data={factures.data}
                emptyMessage="No invoices match your filters."
                emptyIcon={FileText}
                footer={
                    factures.links && factures.links.length > 3 ? (
                        <Pagination links={factures.links} />
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
