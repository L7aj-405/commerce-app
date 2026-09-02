import { useState } from 'react';
import { Link, router, useForm } from '@inertiajs/react';
import { ArrowLeft, Calculator, CheckCircle2, Wallet, Pencil, Ban, X } from 'lucide-react';
import SaasLayout from '@/Layouts/SaasLayout';
import Button from '@/Components/Button';
import StatusBadge from '@/Components/StatusBadge';
import Select from '@/Components/Select';
import EmptyState from '@/Components/EmptyState';
import { formatDateOnly } from '@/Support/formatDate';

function money(amount) {
    return `${Number(amount ?? 0).toLocaleString(undefined, { minimumFractionDigits: 2, maximumFractionDigits: 2 })} MAD`;
}

export default function Show({ period, accounts, can }) {
    const canManage = can?.manage ?? false;
    const items = period.items ?? [];
    const [editTarget, setEditTarget] = useState(null);
    const [payTarget, setPayTarget] = useState(null); // { mode: 'item'|'all', item? }

    const approvedCount = items.filter((i) => i.status === 'approved').length;
    const pendingCount = items.filter((i) => i.status === 'pending').length;
    const totalNet = items.filter((i) => i.status !== 'cancelled').reduce((sum, i) => sum + Number(i.net_amount ?? 0), 0);

    const calculate = () => router.post(`/dashboard/finance/payroll/${period.id}/calculate`, {}, { preserveScroll: true });
    const approve = () => router.post(`/dashboard/finance/payroll/${period.id}/approve`, {}, { preserveScroll: true });

    return (
        <SaasLayout pageHeader={{
            title: `Payroll — ${formatDateOnly(period.period_start)} → ${formatDateOnly(period.period_end)}`,
            subtitle: period.store?.name ?? 'Organization-wide',
            breadcrumbs: [
                { label: 'Dashboard', href: '/dashboard' },
                { label: 'Finance', href: '/dashboard/finance' },
                { label: 'Payroll', href: '/dashboard/finance/payroll' },
                { label: 'Period' },
            ],
            actions: (
                <Link href="/dashboard/finance/payroll" className="inline-flex items-center gap-1.5 px-3 py-2 text-sm font-medium rounded-lg bg-surface-2 border border-line text-content-muted hover:bg-surface-3 hover:text-content">
                    <ArrowLeft className="w-4 h-4" /> Back
                </Link>
            ),
        }}>
            <div className="mb-6 flex flex-wrap items-center gap-3">
                <StatusBadge status={period.status} type="payroll_period" />
                <span className="text-sm text-content-muted">{items.length} employee(s) · <span className="font-semibold text-content tabular-nums">{money(totalNet)}</span> total net</span>

                {canManage && (
                    <div className="ml-auto flex gap-2">
                        {['draft', 'calculated'].includes(period.status) && (
                            <Button variant="secondary" icon={Calculator} onClick={calculate}>{period.status === 'draft' ? 'Calculate' : 'Recalculate'}</Button>
                        )}
                        {period.status === 'calculated' && pendingCount > 0 && (
                            <Button icon={CheckCircle2} onClick={approve}>Approve payroll</Button>
                        )}
                        {period.status === 'approved' && approvedCount > 0 && (
                            <Button icon={Wallet} onClick={() => setPayTarget({ mode: 'all' })}>Pay all approved ({approvedCount})</Button>
                        )}
                    </div>
                )}
            </div>

            {items.length === 0 ? (
                <EmptyState icon={Calculator} title="Not calculated yet" description={canManage ? 'Click "Calculate" to generate salary-due lines for every active employee.' : 'This payroll period has no lines yet.'} />
            ) : (
                <div className="overflow-x-auto rounded-xl border border-line">
                    <table className="w-full text-sm">
                        <thead className="bg-surface-2 text-xs uppercase tracking-wider text-content-muted">
                            <tr>
                                <th className="px-3 py-2.5 text-left">Employee</th>
                                <th className="px-3 py-2.5 text-right">Base</th>
                                <th className="px-3 py-2.5 text-right">Bonus</th>
                                <th className="px-3 py-2.5 text-right">Deduction</th>
                                <th className="px-3 py-2.5 text-right">Advance</th>
                                <th className="px-3 py-2.5 text-right">Net</th>
                                <th className="px-3 py-2.5 text-left">Status</th>
                                {canManage && <th className="px-3 py-2.5"></th>}
                            </tr>
                        </thead>
                        <tbody className="divide-y divide-line">
                            {items.map((item) => (
                                <tr key={item.id}>
                                    <td className="px-3 py-2.5">
                                        <p className="font-medium text-content">{item.employee?.display_name}</p>
                                        <p className="text-xs text-content-muted">{item.employee?.role_type?.replace(/_/g, ' ') ?? '—'}</p>
                                    </td>
                                    <td className="px-3 py-2.5 text-right tabular-nums">{money(item.base_amount)}</td>
                                    <td className="px-3 py-2.5 text-right tabular-nums text-success">{Number(item.bonus_amount) > 0 ? `+${money(item.bonus_amount)}` : '—'}</td>
                                    <td className="px-3 py-2.5 text-right tabular-nums text-danger">{Number(item.deduction_amount) > 0 ? `-${money(item.deduction_amount)}` : '—'}</td>
                                    <td className="px-3 py-2.5 text-right tabular-nums text-danger">{Number(item.advance_deduction_amount) > 0 ? `-${money(item.advance_deduction_amount)}` : '—'}</td>
                                    <td className="px-3 py-2.5 text-right font-semibold tabular-nums text-content">{money(item.net_amount)}</td>
                                    <td className="px-3 py-2.5"><StatusBadge status={item.status} type="payroll_item" /></td>
                                    {canManage && (
                                        <td className="px-3 py-2.5">
                                            <div className="flex justify-end gap-1">
                                                {item.status === 'pending' && (
                                                    <button type="button" onClick={() => setEditTarget(item)} className="p-1.5 rounded-lg text-content-muted hover:text-content hover:bg-surface-3" title="Edit bonus/deduction"><Pencil className="w-3.5 h-3.5" /></button>
                                                )}
                                                {item.status === 'approved' && (
                                                    <button type="button" onClick={() => setPayTarget({ mode: 'item', item })} className="p-1.5 rounded-lg text-content-muted hover:text-success hover:bg-success-soft" title="Pay"><Wallet className="w-3.5 h-3.5" /></button>
                                                )}
                                                {item.status !== 'cancelled' && (
                                                    <button type="button" onClick={() => { if (confirm('Cancel this payroll line? A paid line will be reversed, not deleted.')) router.post(`/dashboard/finance/payroll/items/${item.id}/cancel`, {}, { preserveScroll: true }); }}
                                                        className="p-1.5 rounded-lg text-content-muted hover:text-danger hover:bg-danger-soft" title="Cancel"><Ban className="w-3.5 h-3.5" /></button>
                                                )}
                                            </div>
                                        </td>
                                    )}
                                </tr>
                            ))}
                        </tbody>
                    </table>
                </div>
            )}

            {editTarget && <EditItemModal item={editTarget} onClose={() => setEditTarget(null)} />}
            {payTarget && (
                <PayModal
                    target={payTarget}
                    period={period}
                    accounts={accounts}
                    onClose={() => setPayTarget(null)}
                />
            )}
        </SaasLayout>
    );
}

function EditItemModal({ item, onClose }) {
    const { data, setData, patch, processing, errors } = useForm({
        bonus_amount: item.bonus_amount, deduction_amount: item.deduction_amount, notes: item.notes ?? '',
    });

    const submit = (e) => {
        e.preventDefault();
        patch(`/dashboard/finance/payroll/items/${item.id}`, { preserveScroll: true, onSuccess: onClose });
    };

    return (
        <Modal title={`Adjust — ${item.employee?.display_name}`} onClose={onClose}>
            <form onSubmit={submit} className="space-y-4">
                <div className="grid grid-cols-2 gap-3">
                    <div>
                        <label className="block text-xs font-medium text-content-muted mb-1">Bonus</label>
                        <input type="number" step="0.01" min="0" value={data.bonus_amount} onChange={(e) => setData('bonus_amount', e.target.value)}
                            className="w-full px-3 py-2 text-sm rounded-lg bg-surface-3 border border-line text-content" />
                        {errors.bonus_amount && <p className="text-xs text-danger mt-1">{errors.bonus_amount}</p>}
                    </div>
                    <div>
                        <label className="block text-xs font-medium text-content-muted mb-1">Deduction</label>
                        <input type="number" step="0.01" min="0" value={data.deduction_amount} onChange={(e) => setData('deduction_amount', e.target.value)}
                            className="w-full px-3 py-2 text-sm rounded-lg bg-surface-3 border border-line text-content" />
                        {errors.deduction_amount && <p className="text-xs text-danger mt-1">{errors.deduction_amount}</p>}
                    </div>
                </div>
                <div>
                    <label className="block text-xs font-medium text-content-muted mb-1">Notes</label>
                    <textarea value={data.notes} onChange={(e) => setData('notes', e.target.value)} rows={2}
                        className="w-full px-3 py-2 text-sm rounded-lg bg-surface-3 border border-line text-content" />
                </div>
                <div className="flex justify-end gap-2 pt-2">
                    <Button type="button" variant="secondary" onClick={onClose}>Cancel</Button>
                    <Button type="submit" loading={processing}>Save</Button>
                </div>
            </form>
        </Modal>
    );
}

function PayModal({ target, period, accounts, onClose }) {
    const isAll = target.mode === 'all';
    const { data, setData, post, processing, errors } = useForm({
        account_id: accounts.find((a) => a.type === 'bank')?.id ?? accounts[0]?.id ?? '',
        reference: '',
    });

    const submit = (e) => {
        e.preventDefault();
        const url = isAll
            ? `/dashboard/finance/payroll/${period.id}/pay-all`
            : `/dashboard/finance/payroll/items/${target.item.id}/pay`;
        post(url, { preserveScroll: true, onSuccess: onClose });
    };

    return (
        <Modal title={isAll ? 'Pay all approved items' : `Pay — ${target.item.employee?.display_name}`} onClose={onClose}>
            <form onSubmit={submit} className="space-y-4">
                {! isAll && (
                    <p className="text-sm text-content-muted">Net amount: <span className="font-semibold text-content tabular-nums">{money(target.item.net_amount)}</span></p>
                )}
                <div>
                    <label className="block text-xs font-medium text-content-muted mb-1">Pay from account</label>
                    <Select value={data.account_id} onChange={(v) => setData('account_id', v)}
                        options={accounts.map((a) => ({ value: a.id, label: a.name }))} error={Boolean(errors.account_id)} />
                    {errors.account_id && <p className="text-xs text-danger mt-1">{errors.account_id}</p>}
                </div>
                {! isAll && (
                    <div>
                        <label className="block text-xs font-medium text-content-muted mb-1">Reference (optional)</label>
                        <input value={data.reference} onChange={(e) => setData('reference', e.target.value)} className="w-full px-3 py-2 text-sm rounded-lg bg-surface-3 border border-line text-content" />
                    </div>
                )}
                <div className="flex justify-end gap-2 pt-2">
                    <Button type="button" variant="secondary" onClick={onClose}>Cancel</Button>
                    <Button type="submit" loading={processing} disabled={! data.account_id}>Confirm payment</Button>
                </div>
            </form>
        </Modal>
    );
}

function Modal({ title, onClose, children }) {
    return (
        <div className="fixed inset-0 z-50 flex items-center justify-center bg-black/40 px-4 overflow-y-auto py-8">
            <div className="w-full max-w-md rounded-xl bg-surface-2 border border-line p-6 shadow-xl">
                <div className="flex items-center justify-between mb-4">
                    <h3 className="text-base font-semibold text-content">{title}</h3>
                    <button type="button" onClick={onClose} className="text-content-muted hover:text-content"><X className="w-4 h-4" /></button>
                </div>
                {children}
            </div>
        </div>
    );
}
