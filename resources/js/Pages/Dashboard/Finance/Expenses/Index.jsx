import { useState } from 'react';
import { Link, router, usePage } from '@inertiajs/react';
import { Plus, Receipt, Pencil, CheckCircle2, RotateCcw, Ban, Trash2, Paperclip, XCircle } from 'lucide-react';
import SaasLayout from '@/Layouts/SaasLayout';
import DataTable from '@/Components/DataTable';
import StatusBadge from '@/Components/StatusBadge';
import Button from '@/Components/Button';
import JustificationBadges from '@/Components/Finance/JustificationBadges';

function money(amount, currency = 'MAD') {
    return `${Number(amount ?? 0).toLocaleString(undefined, { minimumFractionDigits: 2, maximumFractionDigits: 2 })} ${currency}`;
}

export default function Index({ expenses, filters, options, can }) {
    const permissions = usePage().props.auth?.permissions ?? [];
    const canManage = permissions.includes('*') || permissions.includes('finance.manage_expenses');
    const canReview = can?.review ?? false;
    const rows = expenses?.data ?? [];
    const [local, setLocal] = useState({
        from: filters.from ?? '', to: filters.to ?? '', category_id: filters.category_id ?? '',
        vendor_id: filters.vendor_id ?? '', status: filters.status ?? '', payment_method: filters.payment_method ?? '', store_id: filters.store_id ?? '',
        justification_status: filters.justification_status ?? '', owner_review_status: filters.owner_review_status ?? '',
    });

    const applyFilters = (next) => {
        const merged = { ...local, ...next };
        setLocal(merged);
        router.get('/dashboard/finance/expenses', Object.fromEntries(Object.entries(merged).filter(([, v]) => v !== '')), { preserveState: true, replace: true });
    };

    const columns = [
        { key: 'expense_date', label: 'Date', render: (e) => e.expense_date },
        { key: 'title', label: 'Title', render: (e) => (
            <div>
                <p className="font-medium text-content flex items-center gap-1.5">
                    {e.title}
                    {e.documents_count > 0 && (
                        <span className="inline-flex items-center gap-0.5 text-xs text-content-muted" title={`${e.documents_count} document(s)`}>
                            <Paperclip className="w-3 h-3" />{e.documents_count}
                        </span>
                    )}
                </p>
                <p className="text-xs text-content-muted">{e.category?.name ?? '—'}{e.vendor ? ` · ${e.vendor.name}` : ''}{e.store ? ` · ${e.store.name}` : ' · Organization-level'}</p>
                <div className="mt-1 flex flex-wrap gap-1"><JustificationBadges expense={e} /></div>
            </div>
        ) },
        { key: 'amount', label: 'Amount', align: 'right', render: (e) => <span className="font-semibold tabular-nums text-content">{money(e.amount, e.currency)}</span> },
        { key: 'payment_method', label: 'Method', render: (e) => e.payment_method ? e.payment_method.replace(/_/g, ' ') : '—' },
        { key: 'status', label: 'Status', render: (e) => <StatusBadge status={e.status} type="payment" /> },
        ...(canManage || canReview ? [{ key: 'actions', label: '', align: 'right', render: (e) => (
            <div className="flex justify-end gap-1">
                {canManage && <Link href={`/dashboard/finance/expenses/${e.id}/edit`} className="p-1.5 rounded-lg text-content-muted hover:text-content hover:bg-surface-3" aria-label="Edit">
                    <Pencil className="w-3.5 h-3.5" />
                </Link>}
                {canReview && ['pending', 'needs_more_info'].includes(e.owner_review_status) && (
                    <>
                        <button type="button" onClick={() => router.post(`/dashboard/finance/expenses/${e.id}/approve`, {}, { preserveScroll: true })}
                            className="p-1.5 rounded-lg text-content-muted hover:text-success hover:bg-success-soft" aria-label="Approve" title="Approve internally">
                            <CheckCircle2 className="w-3.5 h-3.5" />
                        </button>
                        <button type="button" onClick={() => { const note = prompt('Reason for rejecting (optional):') ?? ''; router.post(`/dashboard/finance/expenses/${e.id}/reject`, { note }, { preserveScroll: true }); }}
                            className="p-1.5 rounded-lg text-content-muted hover:text-danger hover:bg-danger-soft" aria-label="Reject" title="Reject">
                            <XCircle className="w-3.5 h-3.5" />
                        </button>
                    </>
                )}
                {canManage && e.status === 'unpaid' && (
                    <button type="button" onClick={() => router.post(`/dashboard/finance/expenses/${e.id}/mark-paid`, {}, { preserveScroll: true })}
                        className="p-1.5 rounded-lg text-content-muted hover:text-success hover:bg-success-soft" aria-label="Mark paid" title="Mark paid">
                        <CheckCircle2 className="w-3.5 h-3.5" />
                    </button>
                )}
                {canManage && e.status === 'paid' && (
                    <button type="button" onClick={() => router.post(`/dashboard/finance/expenses/${e.id}/mark-unpaid`, {}, { preserveScroll: true })}
                        className="p-1.5 rounded-lg text-content-muted hover:text-warning hover:bg-warning-soft" aria-label="Mark unpaid" title="Mark unpaid">
                        <RotateCcw className="w-3.5 h-3.5" />
                    </button>
                )}
                {canManage && e.status !== 'cancelled' && (
                    <button type="button" onClick={() => { if (confirm('Cancel this expense?')) router.post(`/dashboard/finance/expenses/${e.id}/cancel`, {}, { preserveScroll: true }); }}
                        className="p-1.5 rounded-lg text-content-muted hover:text-danger hover:bg-danger-soft" aria-label="Cancel" title="Cancel">
                        <Ban className="w-3.5 h-3.5" />
                    </button>
                )}
                {canManage && (
                    <button type="button" onClick={() => { if (confirm('Delete this expense? Expenses generated by a recurring charge are cancelled instead.')) router.delete(`/dashboard/finance/expenses/${e.id}`, { preserveScroll: true }); }}
                        className="p-1.5 rounded-lg text-content-muted hover:text-danger hover:bg-danger-soft" aria-label="Delete" title="Delete">
                        <Trash2 className="w-3.5 h-3.5" />
                    </button>
                )}
            </div>
        ) }] : []),
    ];

    return (
        <SaasLayout pageHeader={{
            title: 'Expenses',
            subtitle: 'Everything the business has paid or owes',
            breadcrumbs: [{ label: 'Dashboard', href: '/dashboard' }, { label: 'Finance', href: '/dashboard/finance' }, { label: 'Expenses' }],
            actions: canManage ? <Link href="/dashboard/finance/expenses/create"><Button icon={Plus}>Record expense</Button></Link> : null,
        }}>
            <div className="mb-4 grid grid-cols-2 sm:grid-cols-3 lg:grid-cols-9 gap-2">
                <input type="date" value={local.from} onChange={(e) => applyFilters({ from: e.target.value })} className="px-2.5 py-2 text-xs rounded-lg bg-surface-2 border border-line text-content" title="From" />
                <input type="date" value={local.to} onChange={(e) => applyFilters({ to: e.target.value })} className="px-2.5 py-2 text-xs rounded-lg bg-surface-2 border border-line text-content" title="To" />
                <select value={local.category_id} onChange={(e) => applyFilters({ category_id: e.target.value })} className="px-2.5 py-2 text-xs rounded-lg bg-surface-2 border border-line text-content">
                    <option value="">All categories</option>
                    {options.categories.map((c) => <option key={c.id} value={c.id}>{c.name}</option>)}
                </select>
                <select value={local.vendor_id} onChange={(e) => applyFilters({ vendor_id: e.target.value })} className="px-2.5 py-2 text-xs rounded-lg bg-surface-2 border border-line text-content">
                    <option value="">All vendors</option>
                    {options.vendors.map((v) => <option key={v.id} value={v.id}>{v.name}</option>)}
                </select>
                <select value={local.store_id} onChange={(e) => applyFilters({ store_id: e.target.value })} className="px-2.5 py-2 text-xs rounded-lg bg-surface-2 border border-line text-content">
                    <option value="">All stores</option>
                    {options.stores.map((s) => <option key={s.id} value={s.id}>{s.name}</option>)}
                </select>
                <select value={local.status} onChange={(e) => applyFilters({ status: e.target.value })} className="px-2.5 py-2 text-xs rounded-lg bg-surface-2 border border-line text-content">
                    <option value="">All statuses</option>
                    <option value="unpaid">Unpaid</option>
                    <option value="paid">Paid</option>
                    <option value="cancelled">Cancelled</option>
                </select>
                <select value={local.payment_method} onChange={(e) => applyFilters({ payment_method: e.target.value })} className="px-2.5 py-2 text-xs rounded-lg bg-surface-2 border border-line text-content">
                    <option value="">All methods</option>
                    <option value="cash">Cash</option>
                    <option value="bank_transfer">Bank transfer</option>
                    <option value="card">Card</option>
                    <option value="cheque">Cheque</option>
                    <option value="cod_settlement">COD settlement</option>
                    <option value="other">Other</option>
                </select>
                <select value={local.justification_status} onChange={(e) => applyFilters({ justification_status: e.target.value })} className="px-2.5 py-2 text-xs rounded-lg bg-surface-2 border border-line text-content">
                    <option value="">All documentation</option>
                    <option value="documented">Documented</option>
                    <option value="internal_only">Internal voucher only</option>
                    <option value="needs_review">Needs owner review</option>
                </select>
                {canReview && (
                    <select value={local.owner_review_status} onChange={(e) => applyFilters({ owner_review_status: e.target.value })} className="px-2.5 py-2 text-xs rounded-lg bg-surface-2 border border-line text-content">
                        <option value="">All review statuses</option>
                        <option value="pending">Pending review</option>
                        <option value="approved">Approved</option>
                        <option value="rejected">Rejected</option>
                        <option value="needs_more_info">Needs more info</option>
                    </select>
                )}
            </div>

            <DataTable columns={columns} data={rows} emptyIcon={Receipt} emptyMessage="No expenses match these filters." />

            {Array.isArray(expenses?.data) && expenses.links?.length > 3 && (
                <nav className="flex flex-wrap items-center justify-end gap-1 mt-6">
                    {expenses.links.map((l, i) => (
                        <Link key={i} href={l.url ?? '#'} preserveScroll dangerouslySetInnerHTML={{ __html: l.label }}
                            className={`min-w-8 px-2.5 py-1 rounded-md text-xs transition ${l.active ? 'bg-primary text-white' : 'text-content-muted hover:bg-surface-3 bg-surface-2 border border-line'} ${l.url ? '' : 'opacity-40 pointer-events-none'}`} />
                    ))}
                </nav>
            )}
        </SaasLayout>
    );
}
