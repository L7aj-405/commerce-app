import { Head, Link, useForm } from '@inertiajs/react';
import { Loader2, ArrowLeft, Save, ShieldCheck, Monitor, KeyRound, Lock, CheckCircle2 } from 'lucide-react';
import SaasLayout from '@/Layouts/SaasLayout';

export default function EditMember({ store, roles = [], member, cashier }) {
    const { data, setData, patch, processing, errors } = useForm({
        name:                 member.user.name ?? '',
        store_role_id:        member.store_role_id ?? (roles[0]?.id ?? ''),
        is_active:            member.is_active ?? true,
        pin_code:             '',
        cashier_status:       cashier.status ?? 'active',
        can_give_discounts:   cashier.can_give_discounts ?? false,
        max_discount_percent: cashier.max_discount_percent ?? 0,
        daily_limit:          cashier.daily_limit ?? '',
    });

    const selectedRole = roles.find((r) => r.id === data.store_role_id);
    const posCapable   = member.is_owner || (selectedRole?.pos_access ?? false);

    const submit = (e) => {
        e.preventDefault();
        patch(`/dashboard/team/members/${member.id}`);
    };

    const field = 'w-full px-3 py-2.5 rounded-[var(--radius-button)] bg-surface-2 border border-line text-content placeholder:text-content-muted focus:outline-none focus:border-primary';

    return (
        <>
            <Head title={`Edit ${member.user.name}`} />
            <SaasLayout pageHeader={{
                title: `Edit — ${member.user.name}`,
                subtitle: `Manage this member’s role and access in ${store?.name ?? 'your store'}`,
                breadcrumbs: [
                    { label: 'Dashboard', href: '/dashboard' },
                    { label: 'Team', href: '/dashboard/team' },
                    { label: 'Edit member' },
                ],
                actions: (
                    <Link href="/dashboard/team" className="inline-flex items-center gap-1.5 px-3 py-2 text-sm font-medium rounded-[var(--radius-button)] bg-surface-2 border border-line text-content-muted hover:bg-surface-3">
                        <ArrowLeft className="w-4 h-4" /> Back
                    </Link>
                ),
            }}>
                <form onSubmit={submit} className="max-w-2xl space-y-6">
                    {/* Identity */}
                    <div className="grid grid-cols-1 md:grid-cols-2 gap-4">
                        <div>
                            <label className="block text-xs font-medium text-content-muted mb-1">Full name</label>
                            <input type="text" value={data.name} onChange={(e) => setData('name', e.target.value)}
                                className={field} />
                            {errors.name && <p className="mt-1 text-xs text-danger">{errors.name}</p>}
                        </div>
                        <div>
                            <label className="block text-xs font-medium text-content-muted mb-1">Email</label>
                            <input type="email" value={member.user.email} disabled className={`${field} opacity-60 cursor-not-allowed`} />
                        </div>
                    </div>

                    {/* Role */}
                    {member.is_owner ? (
                        <div className="flex items-center gap-2 px-4 py-3 rounded-[var(--radius-button)] border border-primary/30 bg-primary-soft text-sm text-primary">
                            <ShieldCheck className="w-4 h-4" /> This member is the store owner and always has full access.
                        </div>
                    ) : (
                        <>
                            <fieldset>
                                <div className="flex items-center justify-between mb-2">
                                    <legend className="block text-xs font-medium text-content-muted">Role</legend>
                                    <Link href="/dashboard/roles" className="text-xs text-primary hover:text-primary-strong">Manage roles →</Link>
                                </div>
                                <div className="grid grid-cols-1 md:grid-cols-2 gap-3">
                                    {roles.map((r) => {
                                        const checked = data.store_role_id === r.id;
                                        const Icon = r.pos_only ? Monitor : ShieldCheck;
                                        return (
                                            <label key={r.id} className={`relative flex flex-col gap-2 p-4 rounded-[var(--radius-card)] border cursor-pointer transition ${
                                                checked ? 'border-primary/40 bg-primary-soft' : 'border-line bg-surface-2 hover:border-content-muted/40'}`}>
                                                <input type="radio" name="store_role_id" value={r.id} checked={checked}
                                                    onChange={() => setData('store_role_id', r.id)} className="sr-only" />
                                                <div className="flex items-center justify-between">
                                                    <div className={`flex items-center gap-2 ${checked ? 'text-primary' : 'text-content-muted'}`}>
                                                        <Icon className="w-4 h-4" /><span className="font-semibold">{r.name}</span>
                                                    </div>
                                                    <span className={`w-4 h-4 rounded-full border-2 ${checked ? 'border-primary bg-primary' : 'border-line'}`} />
                                                </div>
                                                {r.description && <p className="text-xs text-content-muted leading-relaxed">{r.description}</p>}
                                            </label>
                                        );
                                    })}
                                </div>
                                {errors.store_role_id && <p className="mt-1 text-xs text-danger">{errors.store_role_id}</p>}
                            </fieldset>

                            <label className="flex items-center gap-3 cursor-pointer">
                                <input type="checkbox" checked={data.is_active} onChange={(e) => setData('is_active', e.target.checked)}
                                    className="h-4 w-4 rounded border-line bg-surface text-primary focus:ring-primary/40" />
                                <span className="text-sm text-content">Active member</span>
                                <span className="text-xs text-content-muted">— unchecking revokes their access without deleting them.</span>
                            </label>
                        </>
                    )}

                    {/* Cashier / POS */}
                    {posCapable && (
                        <div className="rounded-[var(--radius-card)] border border-line bg-surface-2 p-4 space-y-4">
                            <div className="flex items-center justify-between">
                                <div className="flex items-center gap-2 text-sm font-semibold text-content">
                                    <KeyRound className="w-4 h-4 text-primary" /> POS terminal access
                                </div>
                                {cashier.has_pin ? (
                                    <span className="inline-flex items-center gap-1 text-xs text-success">
                                        <CheckCircle2 className="w-3.5 h-3.5" /> PIN is set
                                    </span>
                                ) : (
                                    <span className="text-xs text-warning">No PIN set — they can’t sign in to POS yet</span>
                                )}
                            </div>

                            {cashier.locked && (
                                <div className="flex items-center gap-2 text-xs text-warning">
                                    <Lock className="w-3.5 h-3.5" /> Account is temporarily locked. Setting a new PIN unlocks it.
                                </div>
                            )}

                            <div className="grid grid-cols-1 md:grid-cols-2 gap-4">
                                <div>
                                    <label className="block text-xs font-medium text-content-muted mb-1">
                                        {cashier.has_pin ? 'Reset PIN (4 digits)' : 'Set PIN (4 digits)'}
                                    </label>
                                    <input type="text" inputMode="numeric" maxLength={4} value={data.pin_code}
                                        onChange={(e) => setData('pin_code', e.target.value.replace(/\D/g, '').slice(0, 4))}
                                        placeholder={cashier.has_pin ? 'Leave blank to keep current' : '••••'}
                                        className={`${field} tracking-[0.5em] font-mono ${errors.pin_code ? 'border-danger/60' : ''}`} />
                                    {errors.pin_code && <p className="mt-1 text-xs text-danger">{errors.pin_code}</p>}
                                </div>
                                <div>
                                    <label className="block text-xs font-medium text-content-muted mb-1">Cashier status</label>
                                    <select value={data.cashier_status} onChange={(e) => setData('cashier_status', e.target.value)} className={field}>
                                        <option value="active">Active</option>
                                        <option value="inactive">Inactive</option>
                                        <option value="suspended">Suspended</option>
                                    </select>
                                </div>
                                <div>
                                    <label className="block text-xs font-medium text-content-muted mb-1">Daily limit (optional)</label>
                                    <input type="number" step="0.01" min="0" value={data.daily_limit}
                                        onChange={(e) => setData('daily_limit', e.target.value)} placeholder="No limit" className={field} />
                                </div>
                                <div>
                                    <label className="block text-xs font-medium text-content-muted mb-1">Max discount %</label>
                                    <input type="number" step="0.01" min="0" max="100" value={data.max_discount_percent}
                                        onChange={(e) => setData('max_discount_percent', e.target.value)} className={field}
                                        disabled={! data.can_give_discounts} />
                                </div>
                            </div>

                            <label className="flex items-center gap-3 cursor-pointer">
                                <input type="checkbox" checked={data.can_give_discounts} onChange={(e) => setData('can_give_discounts', e.target.checked)}
                                    className="h-4 w-4 rounded border-line bg-surface text-primary focus:ring-primary/40" />
                                <span className="text-sm text-content">Can give discounts at checkout</span>
                            </label>
                        </div>
                    )}

                    <div className="flex items-center gap-2">
                        <button type="submit" disabled={processing || ! data.name}
                            className="inline-flex items-center gap-1.5 px-4 py-2.5 text-sm font-semibold rounded-[var(--radius-button)] bg-primary text-primary-contrast hover:bg-primary-strong disabled:opacity-50 transition">
                            {processing ? <><Loader2 className="w-4 h-4 animate-spin" /> Saving…</> : <><Save className="w-4 h-4" /> Save changes</>}
                        </button>
                        <Link href="/dashboard/team" className="px-4 py-2.5 text-sm font-medium text-content-muted hover:text-content">Cancel</Link>
                    </div>
                </form>
            </SaasLayout>
        </>
    );
}
