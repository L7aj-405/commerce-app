import { Link, router, usePage } from '@inertiajs/react';
import { Plus, RefreshCw, Pencil, Pause, Play, Ban, AlertTriangle } from 'lucide-react';
import SaasLayout from '@/Layouts/SaasLayout';
import DataTable from '@/Components/DataTable';
import Button from '@/Components/Button';

function money(amount, currency = 'MAD') {
    return `${Number(amount ?? 0).toLocaleString(undefined, { minimumFractionDigits: 2, maximumFractionDigits: 2 })} ${currency}`;
}

const STATUS_STYLE = {
    active: 'bg-success-soft text-success',
    paused: 'bg-warning-soft text-warning',
    cancelled: 'bg-slate-500/15 text-slate-500',
};

export default function Index({ recurring }) {
    const can = usePage().props.auth?.permissions ?? [];
    const canManage = can.includes('*') || can.includes('finance.manage_recurring');
    const today = new Date().toISOString().slice(0, 10);

    const columns = [
        { key: 'title', label: 'Subscription', render: (r) => (
            <div>
                <p className="font-medium text-content">{r.title}</p>
                <p className="text-xs text-content-muted">{r.category?.name ?? '—'}{r.vendor ? ` · ${r.vendor.name}` : ''}</p>
            </div>
        ) },
        { key: 'amount', label: 'Amount', align: 'right', render: (r) => <span className="font-semibold tabular-nums text-content">{money(r.amount, r.currency)}</span> },
        { key: 'frequency', label: 'Frequency', render: (r) => <span className="capitalize">{r.frequency}</span> },
        { key: 'next_due_at', label: 'Next due', render: (r) => (
            <span className={`flex items-center gap-1.5 ${r.status === 'active' && r.next_due_at < today ? 'text-danger font-medium' : ''}`}>
                {r.status === 'active' && r.next_due_at < today && <AlertTriangle className="w-3.5 h-3.5" />}
                {r.next_due_at}
            </span>
        ) },
        { key: 'status', label: 'Status', render: (r) => (
            <span className={`inline-flex px-2 py-0.5 rounded-full text-xs font-medium capitalize ${STATUS_STYLE[r.status] ?? 'bg-slate-500/15 text-slate-500'}`}>{r.status}</span>
        ) },
        { key: 'generated', label: 'Generated', align: 'right', render: (r) => r.generated_expenses_count ?? 0 },
        ...(canManage ? [{ key: 'actions', label: '', align: 'right', render: (r) => (
            <div className="flex justify-end gap-1">
                <Link href={`/dashboard/finance/recurring/${r.id}/edit`} className="p-1.5 rounded-lg text-content-muted hover:text-content hover:bg-surface-3" aria-label="Edit">
                    <Pencil className="w-3.5 h-3.5" />
                </Link>
                {r.status === 'active' && (
                    <button type="button" onClick={() => router.post(`/dashboard/finance/recurring/${r.id}/pause`, {}, { preserveScroll: true })}
                        className="p-1.5 rounded-lg text-content-muted hover:text-warning hover:bg-warning-soft" aria-label="Pause" title="Pause">
                        <Pause className="w-3.5 h-3.5" />
                    </button>
                )}
                {r.status === 'paused' && (
                    <button type="button" onClick={() => router.post(`/dashboard/finance/recurring/${r.id}/resume`, {}, { preserveScroll: true })}
                        className="p-1.5 rounded-lg text-content-muted hover:text-success hover:bg-success-soft" aria-label="Resume" title="Resume">
                        <Play className="w-3.5 h-3.5" />
                    </button>
                )}
                {r.status !== 'cancelled' && (
                    <button type="button" onClick={() => { if (confirm('Cancel this recurring expense? No further expenses will be generated.')) router.post(`/dashboard/finance/recurring/${r.id}/cancel`, {}, { preserveScroll: true }); }}
                        className="p-1.5 rounded-lg text-content-muted hover:text-danger hover:bg-danger-soft" aria-label="Cancel" title="Cancel">
                        <Ban className="w-3.5 h-3.5" />
                    </button>
                )}
            </div>
        ) }] : []),
    ];

    return (
        <SaasLayout pageHeader={{
            title: 'Recurring expenses & subscriptions',
            subtitle: 'Domains, hosting, software, rent — anything that repeats',
            breadcrumbs: [{ label: 'Dashboard', href: '/dashboard' }, { label: 'Finance', href: '/dashboard/finance' }, { label: 'Recurring' }],
            actions: canManage ? <Link href="/dashboard/finance/recurring/create"><Button icon={Plus}>Add subscription</Button></Link> : null,
        }}>
            <DataTable columns={columns} data={recurring} emptyIcon={RefreshCw} emptyMessage="No recurring expenses yet." />
        </SaasLayout>
    );
}
