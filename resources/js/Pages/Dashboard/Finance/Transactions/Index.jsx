import { useState } from 'react';
import { Link, router, useForm } from '@inertiajs/react';
import { Plus, ListOrdered, X } from 'lucide-react';
import SaasLayout from '@/Layouts/SaasLayout';
import DataTable from '@/Components/DataTable';
import Button from '@/Components/Button';
import { formatDateTime } from '@/Support/formatDate';

function money(amount, currency = 'MAD') {
    return `${Number(amount ?? 0).toLocaleString(undefined, { minimumFractionDigits: 2, maximumFractionDigits: 2 })} ${currency}`;
}

const DIRECTION_STYLE = {
    in: 'bg-success-soft text-success',
    out: 'bg-danger-soft text-danger',
    neutral: 'bg-slate-500/15 text-slate-500',
};

export default function Index({ transactions, filters, options, can }) {
    const rows = transactions?.data ?? [];
    const [local, setLocal] = useState({
        from: filters.from ?? '', to: filters.to ?? '', type: filters.type ?? '',
        direction: filters.direction ?? '', account_id: filters.account_id ?? '', store_id: filters.store_id ?? '',
    });
    const [showAdjustment, setShowAdjustment] = useState(false);

    const applyFilters = (next) => {
        const merged = { ...local, ...next };
        setLocal(merged);
        router.get('/dashboard/finance/transactions', Object.fromEntries(Object.entries(merged).filter(([, v]) => v !== '')), { preserveState: true, replace: true });
    };

    const columns = [
        { key: 'occurred_at', label: 'Date', render: (t) => <span className="whitespace-nowrap">{formatDateTime(t.occurred_at)}</span> },
        { key: 'type', label: 'Type', render: (t) => (
            <div>
                <p className="font-medium text-content">{options.types.find((o) => o.value === t.type)?.label ?? t.type}</p>
                <p className="text-xs text-content-muted">{t.description ?? t.reference ?? '—'}</p>
            </div>
        ) },
        { key: 'account', label: 'Account', render: (t) => t.account?.name ?? '—' },
        { key: 'store', label: 'Store', render: (t) => t.store?.name ?? 'Organization' },
        { key: 'direction', label: 'Direction', render: (t) => (
            <span className={`inline-flex px-2 py-0.5 rounded-full text-xs font-medium capitalize ${DIRECTION_STYLE[t.direction] ?? 'bg-slate-500/15 text-slate-500'}`}>{t.direction}</span>
        ) },
        { key: 'amount', label: 'Amount', align: 'right', render: (t) => (
            <span className={`font-semibold tabular-nums ${t.direction === 'in' ? 'text-success' : t.direction === 'out' ? 'text-danger' : 'text-content'}`}>
                {t.direction === 'out' ? '-' : ''}{money(t.amount, t.currency)}
            </span>
        ) },
    ];

    return (
        <SaasLayout pageHeader={{
            title: 'Finance transactions',
            subtitle: 'The append-only cash & sales ledger',
            breadcrumbs: [{ label: 'Dashboard', href: '/dashboard' }, { label: 'Finance', href: '/dashboard/finance' }, { label: 'Transactions' }],
            actions: can.manage_cashflow ? <Button icon={Plus} onClick={() => setShowAdjustment(true)}>Add adjustment</Button> : null,
        }}>
            <div className="mb-4 grid grid-cols-2 sm:grid-cols-3 lg:grid-cols-6 gap-2">
                <input type="date" value={local.from} onChange={(e) => applyFilters({ from: e.target.value })} className="px-2.5 py-2 text-xs rounded-lg bg-surface-2 border border-line text-content" title="From" />
                <input type="date" value={local.to} onChange={(e) => applyFilters({ to: e.target.value })} className="px-2.5 py-2 text-xs rounded-lg bg-surface-2 border border-line text-content" title="To" />
                <select value={local.type} onChange={(e) => applyFilters({ type: e.target.value })} className="px-2.5 py-2 text-xs rounded-lg bg-surface-2 border border-line text-content">
                    <option value="">All types</option>
                    {options.types.map((t) => <option key={t.value} value={t.value}>{t.label}</option>)}
                </select>
                <select value={local.direction} onChange={(e) => applyFilters({ direction: e.target.value })} className="px-2.5 py-2 text-xs rounded-lg bg-surface-2 border border-line text-content">
                    <option value="">All directions</option>
                    <option value="in">Cash in</option>
                    <option value="out">Cash out</option>
                    <option value="neutral">Neutral</option>
                </select>
                <select value={local.account_id} onChange={(e) => applyFilters({ account_id: e.target.value })} className="px-2.5 py-2 text-xs rounded-lg bg-surface-2 border border-line text-content">
                    <option value="">All accounts</option>
                    {options.accounts.map((a) => <option key={a.id} value={a.id}>{a.name}</option>)}
                </select>
                <select value={local.store_id} onChange={(e) => applyFilters({ store_id: e.target.value })} className="px-2.5 py-2 text-xs rounded-lg bg-surface-2 border border-line text-content">
                    <option value="">All stores</option>
                    {options.stores.map((s) => <option key={s.id} value={s.id}>{s.name}</option>)}
                </select>
            </div>

            <DataTable columns={columns} data={rows} emptyIcon={ListOrdered} emptyMessage="No transactions match these filters." />

            {Array.isArray(transactions?.data) && transactions.links?.length > 3 && (
                <nav className="flex flex-wrap items-center justify-end gap-1 mt-6">
                    {transactions.links.map((l, i) => (
                        <Link key={i} href={l.url ?? '#'} preserveScroll dangerouslySetInnerHTML={{ __html: l.label }}
                            className={`min-w-8 px-2.5 py-1 rounded-md text-xs transition ${l.active ? 'bg-primary text-white' : 'text-content-muted hover:bg-surface-3 bg-surface-2 border border-line'} ${l.url ? '' : 'opacity-40 pointer-events-none'}`} />
                    ))}
                </nav>
            )}

            {showAdjustment && (
                <AdjustmentModal options={options} onClose={() => setShowAdjustment(false)} />
            )}
        </SaasLayout>
    );
}

function AdjustmentModal({ options, onClose }) {
    const { data, setData, post, processing, errors } = useForm({
        direction: 'in', amount: '', account_id: '', store_id: '', occurred_at: new Date().toISOString().slice(0, 10), reference: '', description: '',
    });

    const submit = (e) => {
        e.preventDefault();
        post('/dashboard/finance/transactions', { onSuccess: onClose, preserveScroll: true });
    };

    return (
        <div className="fixed inset-0 z-50 flex items-center justify-center bg-black/40 px-4">
            <div className="w-full max-w-md rounded-xl bg-surface-2 border border-line p-6 shadow-xl">
                <div className="flex items-center justify-between mb-4">
                    <h3 className="text-base font-semibold text-content">Add manual adjustment</h3>
                    <button type="button" onClick={onClose} className="text-content-muted hover:text-content"><X className="w-4 h-4" /></button>
                </div>
                <form onSubmit={submit} className="space-y-4">
                    <div className="grid grid-cols-2 gap-3">
                        <div>
                            <label className="block text-sm font-medium text-content-muted mb-1">Direction</label>
                            <select value={data.direction} onChange={(e) => setData('direction', e.target.value)}
                                className="w-full px-3 py-2 rounded-lg bg-surface-3 border border-line text-content focus:outline-none focus:ring-2 focus:ring-primary">
                                <option value="in">Cash in</option>
                                <option value="out">Cash out</option>
                            </select>
                        </div>
                        <div>
                            <label className="block text-sm font-medium text-content-muted mb-1">Amount</label>
                            <input type="number" step="0.01" min="0.01" value={data.amount} onChange={(e) => setData('amount', e.target.value)}
                                className={`w-full px-3 py-2 rounded-lg bg-surface-3 border ${errors.amount ? 'border-danger' : 'border-line'} text-content focus:outline-none focus:ring-2 focus:ring-primary`} />
                            {errors.amount && <p className="mt-1 text-xs text-danger">{errors.amount}</p>}
                        </div>
                    </div>
                    <div>
                        <label className="block text-sm font-medium text-content-muted mb-1">Account (optional)</label>
                        <select value={data.account_id} onChange={(e) => setData('account_id', e.target.value)}
                            className="w-full px-3 py-2 rounded-lg bg-surface-3 border border-line text-content focus:outline-none focus:ring-2 focus:ring-primary">
                            <option value="">No account</option>
                            {options.accounts.map((a) => <option key={a.id} value={a.id}>{a.name}</option>)}
                        </select>
                    </div>
                    <div>
                        <label className="block text-sm font-medium text-content-muted mb-1">Date</label>
                        <input type="date" value={data.occurred_at} onChange={(e) => setData('occurred_at', e.target.value)}
                            className="w-full px-3 py-2 rounded-lg bg-surface-3 border border-line text-content focus:outline-none focus:ring-2 focus:ring-primary" />
                    </div>
                    <div>
                        <label className="block text-sm font-medium text-content-muted mb-1">Description <span className="text-danger">*</span></label>
                        <textarea value={data.description} onChange={(e) => setData('description', e.target.value)} rows={2}
                            className={`w-full px-3 py-2 rounded-lg bg-surface-3 border ${errors.description ? 'border-danger' : 'border-line'} text-content focus:outline-none focus:ring-2 focus:ring-primary`} />
                        {errors.description && <p className="mt-1 text-xs text-danger">{errors.description}</p>}
                    </div>
                    <div className="flex justify-end gap-2 pt-2">
                        <Button type="button" variant="secondary" onClick={onClose}>Cancel</Button>
                        <Button type="submit" loading={processing}>Record adjustment</Button>
                    </div>
                </form>
            </div>
        </div>
    );
}
