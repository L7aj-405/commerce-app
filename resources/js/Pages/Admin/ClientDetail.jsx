import { useState } from 'react';
import { Link, router } from '@inertiajs/react';
import { ArrowLeft, LifeBuoy, Pause, Play, Store, X } from 'lucide-react';
import SuperAdminLayout from '@/Layouts/SuperAdminLayout';

export default function ClientDetail({ client }) {
    const [supportStore, setSupportStore] = useState(null);
    const [reason, setReason] = useState('');
    const [duration, setDuration] = useState('60');
    const [starting, setStarting] = useState(false);

    const toggle = () => {
        const url = client.is_active ? `/admin/clients/${client.id}/suspend` : `/admin/clients/${client.id}/activate`;
        router.patch(url, {}, { preserveScroll: true });
    };

    const startSupport = (event) => {
        event.preventDefault();
        if (! supportStore || reason.trim().length < 5) return;

        setStarting(true);
        router.post(
            `/admin/clients/${client.id}/stores/${supportStore.id}/support`,
            { reason: reason.trim(), duration: Number(duration) },
            {
                onFinish: () => setStarting(false),
            },
        );
    };

    const closeSupportModal = () => {
        if (starting) return;
        setSupportStore(null);
        setReason('');
        setDuration('60');
    };

    return (
        <SuperAdminLayout pageHeader={{ title: client.name, subtitle: client.email }}>
            <div className="mb-4 flex items-center justify-between">
                <Link
                    href="/admin/clients"
                    className="inline-flex items-center gap-1.5 px-3 py-2 text-xs font-medium rounded-lg bg-surface-2 border border-line text-content-muted hover:bg-surface-3 hover:text-content"
                >
                    <ArrowLeft className="w-4 h-4" /> Back to clients
                </Link>
                <button
                    type="button"
                    onClick={toggle}
                    className={`inline-flex items-center gap-1.5 px-3 py-2 text-xs font-semibold rounded-lg ${
                        client.is_active
                            ? 'bg-red-500/15 text-red-700 dark:text-red-300 border border-red-500/30 hover:bg-red-500/20'
                            : 'bg-emerald-500/15 text-emerald-700 dark:text-emerald-300 border border-emerald-500/30 hover:bg-emerald-500/20'
                    }`}
                >
                    {client.is_active ? <><Pause className="w-3.5 h-3.5" /> Suspend client</> : <><Play className="w-3.5 h-3.5" /> Activate client</>}
                </button>
            </div>

            <div className="grid grid-cols-1 md:grid-cols-3 gap-4 mb-6">
                <Card label="Status" value={client.is_active ? 'Active' : 'Suspended'} tone={client.is_active ? 'green' : 'red'} />
                <Card label="Stores"  value={client.owned_stores_count ?? 0} />
                <Card label="Joined"  value={new Date(client.created_at).toLocaleDateString()} />
            </div>

            <section className="bg-surface-2 border border-line rounded-xl overflow-hidden">
                <header className="px-4 py-3 border-b border-line">
                    <h2 className="text-sm font-semibold text-content">Stores</h2>
                    <p className="mt-0.5 text-xs text-content-muted">
                        Support access is temporary, store-scoped and audited. Customer passwords are never required.
                    </p>
                </header>
                {(client.owned_stores ?? []).length === 0 ? (
                    <div className="p-8 text-center text-sm text-content-muted">No stores yet.</div>
                ) : (
                    <ul className="divide-y divide-line">
                        {client.owned_stores.map((s) => (
                            <li key={s.id} className="flex items-center justify-between gap-4 px-4 py-3">
                                <div className="flex items-center gap-2.5 min-w-0">
                                    <Store className="w-4 h-4 text-content-muted flex-shrink-0" />
                                    <div className="min-w-0">
                                        <div className="text-sm font-medium text-content truncate">{s.name}</div>
                                        <div className="text-xs text-content-muted">{s.country} · {s.currency}</div>
                                    </div>
                                </div>
                                <div className="flex items-center gap-2 flex-shrink-0">
                                    <span className={`text-xs px-2 py-0.5 rounded-full ${s.status === 'active' ? 'bg-emerald-500/15 text-emerald-700 dark:text-emerald-300' : 'bg-red-500/15 text-red-700 dark:text-red-300'}`}>
                                        {s.status}
                                    </span>
                                    <button
                                        type="button"
                                        onClick={() => setSupportStore(s)}
                                        className="inline-flex items-center gap-1.5 px-3 py-1.5 text-xs font-semibold rounded-lg border border-amber-500/30 bg-amber-500/10 text-amber-800 dark:text-amber-300 hover:bg-amber-500/15"
                                    >
                                        <LifeBuoy className="w-3.5 h-3.5" /> Support mode
                                    </button>
                                </div>
                            </li>
                        ))}
                    </ul>
                )}
            </section>

            {supportStore && (
                <div className="fixed inset-0 z-50 flex items-center justify-center p-4">
                    <button type="button" aria-label="Close support modal" className="absolute inset-0 bg-black/60" onClick={closeSupportModal} />
                    <form onSubmit={startSupport} className="relative w-full max-w-lg rounded-xl border border-line bg-surface p-5 shadow-2xl">
                        <div className="flex items-start justify-between gap-4">
                            <div>
                                <div className="flex items-center gap-2 text-sm font-semibold text-content">
                                    <LifeBuoy className="w-4 h-4 text-amber-600" /> Start support session
                                </div>
                                <p className="mt-1 text-xs text-content-muted">
                                    You will access only <strong>{supportStore.name}</strong>. All support mutations are audited under your admin account.
                                </p>
                            </div>
                            <button type="button" onClick={closeSupportModal} className="p-1 text-content-muted hover:text-content">
                                <X className="w-4 h-4" />
                            </button>
                        </div>

                        <label className="block mt-5 text-xs font-medium text-content">
                            Reason for access
                            <textarea
                                value={reason}
                                onChange={(event) => setReason(event.target.value)}
                                rows={4}
                                maxLength={500}
                                required
                                placeholder="Example: Diagnose WooCommerce sync failure reported by the client"
                                className="mt-1.5 w-full rounded-lg border border-line bg-surface-2 px-3 py-2 text-sm text-content outline-none focus:border-amber-500/60"
                            />
                        </label>

                        <label className="block mt-4 text-xs font-medium text-content">
                            Session duration
                            <select
                                value={duration}
                                onChange={(event) => setDuration(event.target.value)}
                                className="mt-1.5 w-full rounded-lg border border-line bg-surface-2 px-3 py-2 text-sm text-content outline-none focus:border-amber-500/60"
                            >
                                <option value="15">15 minutes</option>
                                <option value="30">30 minutes</option>
                                <option value="60">60 minutes</option>
                                <option value="120">120 minutes</option>
                            </select>
                        </label>

                        <div className="mt-5 flex justify-end gap-2">
                            <button type="button" onClick={closeSupportModal} className="px-3 py-2 text-xs font-medium rounded-lg border border-line text-content-muted hover:bg-surface-2">
                                Cancel
                            </button>
                            <button
                                type="submit"
                                disabled={starting || reason.trim().length < 5}
                                className="inline-flex items-center gap-1.5 px-3 py-2 text-xs font-semibold rounded-lg border border-amber-500/30 bg-amber-500/15 text-amber-800 dark:text-amber-300 disabled:opacity-50"
                            >
                                <LifeBuoy className="w-3.5 h-3.5" /> {starting ? 'Starting…' : 'Start support mode'}
                            </button>
                        </div>
                    </form>
                </div>
            )}
        </SuperAdminLayout>
    );
}

function Card({ label, value, tone }) {
    const toneClass =
        tone === 'green' ? 'text-emerald-700 dark:text-emerald-300' :
        tone === 'red'   ? 'text-red-700 dark:text-red-300' : 'text-white';
    return (
        <div className="bg-surface-2 border border-line rounded-xl p-5">
            <div className="text-xs text-content-muted">{label}</div>
            <div className={`mt-1 text-2xl font-bold tabular-nums ${toneClass}`}>{value}</div>
        </div>
    );
}
