import { Head, Link, useForm } from '@inertiajs/react';
import { Loader2, UserPlus, ArrowLeft, ShieldCheck, Monitor, Mail } from 'lucide-react';
import SaasLayout from '@/Layouts/SaasLayout';

export default function AddMember({ store, roles = [] }) {
    const { data, setData, post, processing, errors } = useForm({
        name:                  '',
        email:                 '',
        password:              '',
        password_confirmation: '',
        store_role_id:         roles[0]?.id ?? '',
    });

    const submit = (e) => {
        e.preventDefault();
        post('/dashboard/team/add');
    };

    const field = 'w-full px-3 py-2.5 rounded-[var(--radius-button)] bg-surface-2 border text-content placeholder:text-content-muted focus:outline-none focus:border-primary focus:ring-1 focus:ring-primary';

    return (
        <>
            <Head title="Add team member" />
            <SaasLayout pageHeader={{
                title: 'Add a team member',
                subtitle: `Create an account and add them to ${store?.name ?? 'your store'}`,
                breadcrumbs: [
                    { label: 'Dashboard', href: '/dashboard' },
                    { label: 'Team', href: '/dashboard/team' },
                    { label: 'Add member' },
                ],
                actions: (
                    <Link
                        href="/dashboard/team"
                        className="inline-flex items-center gap-1.5 px-3 py-2 text-sm font-medium rounded-[var(--radius-button)] bg-surface-2 border border-line text-content-muted hover:bg-surface-3"
                    >
                        <ArrowLeft className="w-4 h-4" /> Back
                    </Link>
                ),
            }}>
                <form onSubmit={submit} className="max-w-2xl space-y-5">
                    <div className="grid grid-cols-1 md:grid-cols-2 gap-4">
                        <div>
                            <label className="block text-xs font-medium text-content-muted mb-1">Full name</label>
                            <input
                                type="text"
                                value={data.name}
                                onChange={(e) => setData('name', e.target.value)}
                                placeholder="Jane Doe"
                                autoFocus
                                className={`${field} ${errors.name ? 'border-danger/60' : 'border-line'}`}
                            />
                            {errors.name && <p className="mt-1 text-xs text-danger">{errors.name}</p>}
                        </div>
                        <div>
                            <label className="block text-xs font-medium text-content-muted mb-1">Email address</label>
                            <input
                                type="email"
                                value={data.email}
                                onChange={(e) => setData('email', e.target.value)}
                                placeholder="jane@example.com"
                                className={`${field} ${errors.email ? 'border-danger/60' : 'border-line'}`}
                            />
                            {errors.email && <p className="mt-1 text-xs text-danger">{errors.email}</p>}
                        </div>
                        <div>
                            <label className="block text-xs font-medium text-content-muted mb-1">Password</label>
                            <input
                                type="password"
                                value={data.password}
                                onChange={(e) => setData('password', e.target.value)}
                                placeholder="Min. 8 characters"
                                className={`${field} ${errors.password ? 'border-danger/60' : 'border-line'}`}
                            />
                            {errors.password && <p className="mt-1 text-xs text-danger">{errors.password}</p>}
                        </div>
                        <div>
                            <label className="block text-xs font-medium text-content-muted mb-1">Confirm password</label>
                            <input
                                type="password"
                                value={data.password_confirmation}
                                onChange={(e) => setData('password_confirmation', e.target.value)}
                                placeholder="Repeat password"
                                className={`${field} border-line`}
                            />
                        </div>
                    </div>

                    <p className="flex items-start gap-1.5 text-xs text-content-muted">
                        <Mail className="w-3.5 h-3.5 mt-0.5 flex-shrink-0" />
                        If a user with this email already exists, they’ll simply be added to your team — the password is ignored.
                    </p>

                    <fieldset>
                        <div className="flex items-center justify-between mb-2">
                            <legend className="block text-xs font-medium text-content-muted">Role</legend>
                            <Link href="/dashboard/roles" className="text-xs text-primary hover:text-primary-strong">
                                Manage roles →
                            </Link>
                        </div>
                        <div className="grid grid-cols-1 md:grid-cols-2 gap-3">
                            {roles.map((r) => {
                                const checked = data.store_role_id === r.id;
                                const Icon = r.pos_only ? Monitor : ShieldCheck;
                                return (
                                    <label
                                        key={r.id}
                                        className={`relative flex flex-col gap-2 p-4 rounded-[var(--radius-card)] border cursor-pointer transition ${
                                            checked
                                                ? 'border-primary/40 bg-primary-soft'
                                                : 'border-line bg-surface-2 hover:border-content-muted/40'
                                        }`}
                                    >
                                        <input
                                            type="radio"
                                            name="store_role_id"
                                            value={r.id}
                                            checked={checked}
                                            onChange={() => setData('store_role_id', r.id)}
                                            className="sr-only"
                                        />
                                        <div className="flex items-center justify-between">
                                            <div className={`flex items-center gap-2 ${checked ? 'text-primary' : 'text-content-muted'}`}>
                                                <Icon className="w-4 h-4" />
                                                <span className="font-semibold">{r.name}</span>
                                            </div>
                                            <span className={`w-4 h-4 rounded-full border-2 transition ${
                                                checked ? 'border-primary bg-primary' : 'border-line'
                                            }`} />
                                        </div>
                                        {r.description && <p className="text-xs text-content-muted leading-relaxed">{r.description}</p>}
                                        {r.pos_only && <span className="text-[10px] font-medium uppercase tracking-wide text-success">POS only</span>}
                                    </label>
                                );
                            })}
                        </div>
                        {errors.store_role_id && <p className="mt-1 text-xs text-danger">{errors.store_role_id}</p>}
                    </fieldset>

                    <div className="pt-3 flex items-center gap-2">
                        <button
                            type="submit"
                            disabled={processing || ! data.name || ! data.email}
                            className="inline-flex items-center gap-1.5 px-4 py-2.5 text-sm font-semibold rounded-[var(--radius-button)] bg-primary text-primary-contrast hover:bg-primary-strong disabled:opacity-50 disabled:cursor-not-allowed transition"
                        >
                            {processing ? <><Loader2 className="w-4 h-4 animate-spin" /> Adding…</> : <><UserPlus className="w-4 h-4" /> Add member</>}
                        </button>
                        <Link href="/dashboard/team" className="px-4 py-2.5 text-sm font-medium text-content-muted hover:text-content">Cancel</Link>
                    </div>
                </form>
            </SaasLayout>
        </>
    );
}
