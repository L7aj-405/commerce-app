import { Head, Link, useForm } from '@inertiajs/react';
import { Loader2, UserPlus, ArrowLeft, ShieldCheck, Monitor } from 'lucide-react';
import SaasLayout from '@/Layouts/SaasLayout';

export default function InviteMember({ store, roles = [] }) {
    const { data, setData, post, processing, errors } = useForm({
        email:         '',
        store_role_id: roles[0]?.id ?? '',
    });

    const submit = (e) => {
        e.preventDefault();
        post('/dashboard/team/invite');
    };

    return (
        <>
            <Head title="Invite member" />
            <SaasLayout pageHeader={{
                title: 'Invite a team member',
                subtitle: `Send an invitation to join ${store?.name ?? 'your store'}`,
                breadcrumbs: [
                    { label: 'Dashboard', href: '/dashboard' },
                    { label: 'Team', href: '/dashboard/team' },
                    { label: 'Invite' },
                ],
                actions: (
                    <Link
                        href="/dashboard/team"
                        className="inline-flex items-center gap-1.5 px-3 py-2 text-sm font-medium rounded-lg bg-surface-2 border border-line text-content-muted hover:bg-surface-3"
                    >
                        <ArrowLeft className="w-4 h-4" /> Back
                    </Link>
                ),
            }}>
                <form onSubmit={submit} className="max-w-2xl space-y-5">
                    <div>
                        <label className="block text-xs font-medium text-content-muted mb-1">Email address</label>
                        <input
                            type="email"
                            value={data.email}
                            onChange={(e) => setData('email', e.target.value)}
                            placeholder="colleague@example.com"
                            autoFocus
                            className={`w-full px-3 py-2.5 rounded-lg bg-surface-2 border ${
                                errors.email ? 'border-red-500/60' : 'border-line'
                            } text-content placeholder:text-content-muted focus:outline-none focus:border-indigo-500 focus:ring-1 focus:ring-indigo-500`}
                        />
                        {errors.email && <p className="mt-1 text-xs text-red-600 dark:text-red-400">{errors.email}</p>}
                    </div>

                    <fieldset>
                        <div className="flex items-center justify-between mb-2">
                            <legend className="block text-xs font-medium text-content-muted">Role</legend>
                            <Link href="/dashboard/roles" className="text-xs text-indigo-600 dark:text-indigo-400 hover:text-indigo-700 dark:text-indigo-300">
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
                                        className={`relative flex flex-col gap-2 p-4 rounded-xl border cursor-pointer transition ${
                                            checked
                                                ? 'border-indigo-500/40 bg-indigo-500/5'
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
                                            <div className={`flex items-center gap-2 ${checked ? 'text-white' : 'text-content-muted'}`}>
                                                <Icon className="w-4 h-4" />
                                                <span className="font-semibold">{r.name}</span>
                                            </div>
                                            <span className={`w-4 h-4 rounded-full border-2 transition ${
                                                checked ? 'border-indigo-500 bg-indigo-500' : 'border-slate-600'
                                            }`} />
                                        </div>
                                        {r.description && <p className="text-xs text-content-muted leading-relaxed">{r.description}</p>}
                                        {r.pos_only && <span className="text-[10px] font-medium uppercase tracking-wide text-emerald-600 dark:text-emerald-400">POS only</span>}
                                    </label>
                                );
                            })}
                        </div>
                        {errors.store_role_id && <p className="mt-1 text-xs text-red-600 dark:text-red-400">{errors.store_role_id}</p>}
                    </fieldset>

                    <div className="pt-3 flex items-center gap-2">
                        <button
                            type="submit"
                            disabled={processing || ! data.email}
                            className="inline-flex items-center gap-1.5 px-4 py-2.5 text-sm font-semibold rounded-lg bg-indigo-600 text-white hover:bg-indigo-500 disabled:opacity-50 disabled:cursor-not-allowed transition"
                        >
                            {processing ? <><Loader2 className="w-4 h-4 animate-spin" /> Sending…</> : <><UserPlus className="w-4 h-4" /> Send invitation</>}
                        </button>
                        <p className="text-xs text-content-muted">The link expires in 72 hours.</p>
                    </div>
                </form>
            </SaasLayout>
        </>
    );
}
