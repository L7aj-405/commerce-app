import { useState } from 'react';
import { Link, useForm, router } from '@inertiajs/react';
import { ArrowLeft, Link2, Unlink, Wallet, HandCoins, CheckCircle2, Ban } from 'lucide-react';
import SaasLayout from '@/Layouts/SaasLayout';
import EmployeeForm from '@/Components/Payroll/EmployeeForm';
import Card from '@/Components/Card';
import Button from '@/Components/Button';
import EmptyState from '@/Components/EmptyState';
import StatusBadge from '@/Components/StatusBadge';
import Select from '@/Components/Select';
import { formatDateOnly } from '@/Support/formatDate';

function money(amount, currency = 'MAD') {
    return `${Number(amount ?? 0).toLocaleString(undefined, { minimumFractionDigits: 2, maximumFractionDigits: 2 })} ${currency}`;
}

export default function Edit({ employee, options, can }) {
    const canManage = can?.manage ?? false;

    const { data, setData, patch, processing, errors } = useForm({
        first_name: employee.first_name, last_name: employee.last_name ?? '', display_name: employee.display_name,
        phone: employee.phone ?? '', email: employee.email ?? '', employee_code: employee.employee_code ?? '',
        role_type: employee.role_type ?? '', employment_status: employee.employment_status,
        store_id: employee.store_id ?? '', hired_at: employee.hired_at ?? '', left_at: employee.left_at ?? '', notes: employee.notes ?? '',
    });

    const submit = (e) => { e.preventDefault(); patch(`/dashboard/employees/${employee.id}`); };

    return (
        <SaasLayout pageHeader={{
            title: employee.display_name,
            breadcrumbs: [
                { label: 'Dashboard', href: '/dashboard' },
                { label: 'Employees', href: '/dashboard/employees' },
                { label: employee.display_name },
            ],
            actions: (
                <Link href="/dashboard/employees" className="inline-flex items-center gap-1.5 px-3 py-2 text-sm font-medium rounded-lg bg-surface-2 border border-line text-content-muted hover:bg-surface-3 hover:text-content">
                    <ArrowLeft className="w-4 h-4" /> Back
                </Link>
            ),
        }}>
            <EmployeeForm data={data} setData={setData} errors={errors} processing={processing} options={options} onSubmit={submit} submitLabel="Save changes" />

            <UserLinkCard employee={employee} options={options} canManage={canManage} />
            <SalaryCard employee={employee} canManage={canManage} />
            <AdvancesCard employee={employee} canManage={canManage} canPay={can?.manage_payroll ?? false} accounts={options.accounts ?? []} />
        </SaasLayout>
    );
}

function UserLinkCard({ employee, options, canManage }) {
    const { data, setData, post, processing, reset } = useForm({ user_id: '' });

    const link = (e) => {
        e.preventDefault();
        if (! data.user_id) return;
        post(`/dashboard/employees/${employee.id}/link-user`, { preserveScroll: true, onSuccess: () => reset() });
    };

    const unlink = () => {
        if (confirm('Unlink this user account? The employee record itself is unaffected.')) {
            router.post(`/dashboard/employees/${employee.id}/unlink-user`, {}, { preserveScroll: true });
        }
    };

    return (
        <Card title="Dashboard login" subtitle="Linking never grants or changes permissions — that's still managed under Team" className="max-w-3xl mt-6">
            {employee.user ? (
                <div className="flex items-center justify-between">
                    <div>
                        <p className="text-sm font-medium text-content">{employee.user.name}</p>
                        <p className="text-xs text-content-muted">{employee.user.email}</p>
                    </div>
                    {canManage && <Button variant="secondary" icon={Unlink} onClick={unlink}>Unlink</Button>}
                </div>
            ) : (
                <>
                    <p className="text-sm text-content-muted mb-3">No login linked — this employee can still be paid normally.</p>
                    {canManage && (
                        <form onSubmit={link} className="flex items-center gap-2">
                            <div className="flex-1">
                                <Select value={data.user_id} onChange={(v) => setData('user_id', v)}
                                    options={[{ value: '', label: 'Select a user account' }, ...(options.linkableUsers ?? []).map((u) => ({ value: u.id, label: `${u.name} (${u.email})` }))]} />
                            </div>
                            <Button type="submit" icon={Link2} loading={processing} disabled={! data.user_id}>Link</Button>
                        </form>
                    )}
                </>
            )}
        </Card>
    );
}

function SalaryCard({ employee, canManage }) {
    const [showForm, setShowForm] = useState(false);
    const profiles = employee.salary_profiles ?? [];
    const active = profiles.find((p) => p.is_active);

    const { data, setData, post, processing, errors, reset } = useForm({
        salary_type: 'monthly', base_salary: active?.base_salary ?? '', currency: 'MAD',
        payment_frequency: 'monthly', payment_day: '', effective_from: new Date().toISOString().slice(0, 10), notes: '',
    });

    const submit = (e) => {
        e.preventDefault();
        post(`/dashboard/employees/${employee.id}/salary-profiles`, { preserveScroll: true, onSuccess: () => { reset(); setShowForm(false); } });
    };

    return (
        <Card title="Salary" subtitle="Changing salary keeps history — it never overwrites a past profile" className="max-w-3xl mt-6">
            {active ? (
                <div className="rounded-lg bg-success-soft px-3 py-2.5 text-sm mb-4">
                    <span className="font-semibold text-success tabular-nums">{money(active.base_salary, active.currency)}</span>
                    <span className="text-content-muted"> · {active.payment_frequency} · effective {formatDateOnly(active.effective_from)}</span>
                </div>
            ) : (
                <p className="text-sm text-content-muted mb-4">No salary profile set yet.</p>
            )}

            {canManage && (
                <>
                    {! showForm ? (
                        <Button variant="secondary" icon={Wallet} onClick={() => setShowForm(true)}>{active ? 'Change salary' : 'Set salary'}</Button>
                    ) : (
                        <form onSubmit={submit} className="space-y-3 rounded-lg border border-line bg-surface-3 p-3">
                            <div className="grid grid-cols-2 gap-3">
                                <div>
                                    <label className="block text-xs font-medium text-content-muted mb-1">Base salary</label>
                                    <input type="number" step="0.01" min="0" value={data.base_salary} onChange={(e) => setData('base_salary', e.target.value)}
                                        className="w-full px-3 py-2 text-sm rounded-lg bg-surface-2 border border-line text-content" />
                                    {errors.base_salary && <p className="text-xs text-danger mt-1">{errors.base_salary}</p>}
                                </div>
                                <div>
                                    <label className="block text-xs font-medium text-content-muted mb-1">Effective from</label>
                                    <input type="date" value={data.effective_from} onChange={(e) => setData('effective_from', e.target.value)}
                                        className="w-full px-3 py-2 text-sm rounded-lg bg-surface-2 border border-line text-content" />
                                </div>
                            </div>
                            <div className="grid grid-cols-2 gap-3">
                                <Select value={data.salary_type} onChange={(v) => setData('salary_type', v)} options={[
                                    { value: 'monthly', label: 'Monthly' }, { value: 'weekly', label: 'Weekly' }, { value: 'daily', label: 'Daily' },
                                    { value: 'hourly', label: 'Hourly' }, { value: 'commission_only', label: 'Commission only' }, { value: 'custom', label: 'Custom' },
                                ]} />
                                <Select value={data.payment_frequency} onChange={(v) => setData('payment_frequency', v)} options={[
                                    { value: 'monthly', label: 'Monthly' }, { value: 'weekly', label: 'Weekly' }, { value: 'biweekly', label: 'Every 2 weeks' },
                                ]} />
                            </div>
                            <div className="flex gap-2">
                                <Button type="submit" loading={processing}>Save salary</Button>
                                <Button type="button" variant="secondary" onClick={() => setShowForm(false)}>Cancel</Button>
                            </div>
                        </form>
                    )}
                </>
            )}

            {profiles.length > 1 && (
                <div className="mt-4">
                    <p className="text-xs font-semibold uppercase tracking-wide text-content-muted mb-2">History</p>
                    <ul className="divide-y divide-line rounded-lg border border-line">
                        {profiles.map((p) => (
                            <li key={p.id} className="flex items-center justify-between px-3 py-2 text-sm">
                                <span className="text-content-muted">{formatDateOnly(p.effective_from)} → {p.effective_to ? formatDateOnly(p.effective_to) : 'now'}</span>
                                <span className={`font-medium tabular-nums ${p.is_active ? 'text-success' : 'text-content-muted'}`}>{money(p.base_salary, p.currency)}</span>
                            </li>
                        ))}
                    </ul>
                </div>
            )}
        </Card>
    );
}

function AdvancesCard({ employee, canManage, canPay, accounts }) {
    const [showForm, setShowForm] = useState(false);
    const advances = employee.advances ?? [];

    const { data, setData, post, processing, errors, reset } = useForm({
        amount: '', advance_date: new Date().toISOString().slice(0, 10), reason: '',
    });

    const submit = (e) => {
        e.preventDefault();
        post(`/dashboard/employees/${employee.id}/advances`, { preserveScroll: true, onSuccess: () => { reset(); setShowForm(false); } });
    };

    const approve = (advance) => router.post(`/dashboard/employee-advances/${advance.id}/approve`, {}, { preserveScroll: true });
    const pay = (advance) => {
        const accountId = accounts[0]?.id;
        if (! accountId) { alert('No finance account available.'); return; }
        router.post(`/dashboard/employee-advances/${advance.id}/pay`, { account_id: accountId }, { preserveScroll: true });
    };
    const cancel = (advance) => {
        if (confirm('Cancel this advance?')) router.post(`/dashboard/employee-advances/${advance.id}/cancel`, {}, { preserveScroll: true });
    };

    return (
        <Card title="Advances" subtitle="Requesting an advance never moves cash — only paying it does" className="max-w-3xl mt-6">
            {advances.length === 0 ? (
                <EmptyState icon={HandCoins} title="No advances" description={canManage ? 'Record an advance request below.' : undefined} />
            ) : (
                <ul className="divide-y divide-line rounded-lg border border-line mb-4">
                    {advances.map((a) => (
                        <li key={a.id} className="flex items-center justify-between gap-3 px-3 py-2.5 text-sm">
                            <div>
                                <p className="font-medium text-content tabular-nums">{money(a.amount, a.currency)}</p>
                                <p className="text-xs text-content-muted">{formatDateOnly(a.advance_date)}{a.reason ? ` · ${a.reason}` : ''}</p>
                            </div>
                            <div className="flex items-center gap-2">
                                <StatusBadge status={a.status} type="employee_advance" />
                                {canManage && a.status === 'pending' && (
                                    <button type="button" onClick={() => approve(a)} className="p-1.5 rounded-lg text-content-muted hover:text-success hover:bg-success-soft" title="Approve"><CheckCircle2 className="w-3.5 h-3.5" /></button>
                                )}
                                {canPay && ['pending', 'approved'].includes(a.status) && (
                                    <button type="button" onClick={() => pay(a)} className="p-1.5 rounded-lg text-content-muted hover:text-success hover:bg-success-soft" title="Pay"><Wallet className="w-3.5 h-3.5" /></button>
                                )}
                                {canManage && ['pending', 'approved'].includes(a.status) && (
                                    <button type="button" onClick={() => cancel(a)} className="p-1.5 rounded-lg text-content-muted hover:text-danger hover:bg-danger-soft" title="Cancel"><Ban className="w-3.5 h-3.5" /></button>
                                )}
                            </div>
                        </li>
                    ))}
                </ul>
            )}

            {canManage && (
                <>
                    {! showForm ? (
                        <Button variant="secondary" icon={HandCoins} onClick={() => setShowForm(true)}>Record advance</Button>
                    ) : (
                        <form onSubmit={submit} className="space-y-3 rounded-lg border border-line bg-surface-3 p-3">
                            <div className="grid grid-cols-2 gap-3">
                                <div>
                                    <label className="block text-xs font-medium text-content-muted mb-1">Amount</label>
                                    <input type="number" step="0.01" min="0.01" value={data.amount} onChange={(e) => setData('amount', e.target.value)}
                                        className="w-full px-3 py-2 text-sm rounded-lg bg-surface-2 border border-line text-content" />
                                    {errors.amount && <p className="text-xs text-danger mt-1">{errors.amount}</p>}
                                </div>
                                <div>
                                    <label className="block text-xs font-medium text-content-muted mb-1">Date</label>
                                    <input type="date" value={data.advance_date} onChange={(e) => setData('advance_date', e.target.value)}
                                        className="w-full px-3 py-2 text-sm rounded-lg bg-surface-2 border border-line text-content" />
                                </div>
                            </div>
                            <div>
                                <label className="block text-xs font-medium text-content-muted mb-1">Reason</label>
                                <input value={data.reason} onChange={(e) => setData('reason', e.target.value)} className="w-full px-3 py-2 text-sm rounded-lg bg-surface-2 border border-line text-content" />
                            </div>
                            <div className="flex gap-2">
                                <Button type="submit" loading={processing}>Save</Button>
                                <Button type="button" variant="secondary" onClick={() => setShowForm(false)}>Cancel</Button>
                            </div>
                        </form>
                    )}
                </>
            )}
        </Card>
    );
}
