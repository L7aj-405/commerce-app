import { Link } from '@inertiajs/react';
import { ArrowLeft, ArrowDown, ArrowUp, History } from 'lucide-react';
import SaasLayout from '@/Layouts/SaasLayout';
import DataTable from '@/Components/DataTable';

const TYPE_STYLES = {
    purchase:   { label: 'Purchase',   bg: 'bg-blue-500/15',    text: 'text-blue-300'    },
    sale:       { label: 'Sale',       bg: 'bg-emerald-500/15', text: 'text-emerald-700 dark:text-emerald-300' },
    adjustment: { label: 'Adjustment', bg: 'bg-primary-soft',  text: 'text-primary-strong dark:text-primary'  },
    return:     { label: 'Return',     bg: 'bg-amber-500/15',   text: 'text-amber-700 dark:text-amber-300'   },
    damage:     { label: 'Damage',     bg: 'bg-red-500/15',     text: 'text-red-700 dark:text-red-300'     },
    transfer:   { label: 'Transfer',   bg: 'bg-slate-700/40',   text: 'text-content-muted'   },
};

export default function StockMovements({ movements }) {
    const columns = [
        {
            key: 'created_at',
            label: 'When',
            render: (m) => <span className="text-xs text-content-muted whitespace-nowrap">{formatDate(m.created_at)}</span>,
        },
        {
            key: 'product',
            label: 'Product',
            render: (m) => (
                <div>
                    <div className="text-content font-medium flex items-center gap-1.5">
                        {m.product?.name ?? '—'}
                        {m.variant && (
                            <span className="inline-flex px-1.5 py-0.5 rounded bg-primary-soft text-primary-strong dark:text-primary text-[11px] font-medium">
                                {m.variant.name}
                            </span>
                        )}
                    </div>
                    <div className="text-xs text-content-muted font-mono">{m.variant?.sku ?? m.product?.sku ?? ''}</div>
                </div>
            ),
        },
        {
            key: 'type',
            label: 'Type',
            render: (m) => {
                const t = TYPE_STYLES[m.type] ?? { label: m.type, bg: 'bg-slate-700/40', text: 'text-content-muted' };
                return <span className={`inline-flex px-2 py-0.5 rounded-md text-xs font-medium ${t.bg} ${t.text}`}>{t.label}</span>;
            },
        },
        { key: 'stock_before', label: 'Before', align: 'right', render: (m) => <span className="tabular-nums text-content-muted">{m.stock_before}</span> },
        {
            key: 'quantity_change',
            label: 'Change',
            align: 'right',
            render: (m) => {
                const v = Number(m.quantity_change);
                const isIn = v > 0;
                return (
                    <span className={`inline-flex items-center gap-1 font-semibold tabular-nums ${isIn ? 'text-emerald-600 dark:text-emerald-400' : 'text-red-600 dark:text-red-400'}`}>
                        {isIn ? <ArrowUp className="w-3 h-3" /> : <ArrowDown className="w-3 h-3" />}
                        {isIn ? '+' : ''}{v}
                    </span>
                );
            },
        },
        { key: 'stock_after', label: 'After', align: 'right', render: (m) => <span className="tabular-nums text-content-muted">{m.stock_after}</span> },
        { key: 'user',  label: 'User',  render: (m) => <span className="text-xs text-content-muted">{m.user?.name ?? '—'}</span> },
        { key: 'notes', label: 'Notes', render: (m) => <span className="text-xs text-content-muted">{m.notes || (m.reference_number ? <span className="font-mono">{m.reference_number}</span> : '—')}</span> },
    ];

    return (
        <SaasLayout
            pageHeader={{
                title: 'Stock movements',
                subtitle: 'Every change to inventory, latest first',
                breadcrumbs: [
                    { label: 'Dashboard', href: '/dashboard' },
                    { label: 'Stock', href: '/dashboard/stock' },
                    { label: 'Movements' },
                ],
                actions: (
                    <Link
                        href="/dashboard/stock"
                        className="inline-flex items-center gap-1.5 px-3 py-2 text-sm font-medium rounded-lg bg-surface-2 border border-line text-content-muted hover:bg-surface-3 hover:text-content transition"
                    >
                        <ArrowLeft className="w-4 h-4" />
                        Back to stock
                    </Link>
                ),
            }}
        >
            <DataTable
                columns={columns}
                data={movements.data}
                emptyMessage="No stock movements yet."
                emptyIcon={History}
                footer={
                    movements.links && movements.links.length > 3 ? (
                        <Pagination links={movements.links} />
                    ) : null
                }
            />
        </SaasLayout>
    );
}

function formatDate(iso) {
    if (! iso) return '—';
    try {
        return new Date(iso).toLocaleString([], { dateStyle: 'short', timeStyle: 'short' });
    } catch {
        return iso;
    }
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
                        l.active ? 'bg-primary text-white' : 'text-content-muted hover:bg-surface-3',
                        l.url ? '' : 'opacity-40 pointer-events-none',
                    ].join(' ')}
                />
            ))}
        </nav>
    );
}
