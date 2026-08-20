import { Link } from '@inertiajs/react';
import { CheckCircle2, Plug, ExternalLink } from 'lucide-react';
import SaasLayout from '@/Layouts/SaasLayout';

const ICONS = {
    woocommerce: { letter: 'W', bg: 'from-purple-500 to-purple-700' },
    shopify:     { letter: 'S', bg: 'from-emerald-500 to-emerald-700' },
    youcan:      { letter: 'Y', bg: 'from-indigo-500 to-indigo-700' },
    whatsapp:    { letter: 'W', bg: 'from-green-500 to-green-700' },
};

export default function Index({ store, providers = [], connections = [] }) {
    const connectionByPlatform = Object.fromEntries(
        connections.map((c) => [c.platform, c])
    );

    return (
        <SaasLayout pageHeader={{
            title: 'Integrations',
            subtitle: 'Connect external platforms to sync products, orders, and stock',
            breadcrumbs: [{ label: 'Dashboard', href: '/dashboard' }, { label: 'Integrations' }],
        }}>
            <div className="grid grid-cols-1 md:grid-cols-2 gap-4">
                {providers.map((p) => {
                    const conn = connectionByPlatform[p.key];
                    const connected = conn && conn.status === 'active';
                    const ico = ICONS[p.key] ?? { letter: p.name[0], bg: 'from-slate-500 to-slate-700' };

                    return (
                        <div key={p.key} className="bg-surface-2 border border-line rounded-xl p-5">
                            <div className="flex items-start gap-3">
                                <div className={`w-10 h-10 rounded-lg bg-gradient-to-br ${ico.bg} text-white font-bold flex items-center justify-center text-sm flex-shrink-0`}>
                                    {ico.letter}
                                </div>
                                <div className="flex-1 min-w-0">
                                    <div className="flex items-start justify-between gap-2">
                                        <h3 className="text-base font-semibold text-content">{p.name}</h3>
                                        {connected ? (
                                            <span className="inline-flex items-center gap-1 px-2 py-0.5 text-[10px] font-bold uppercase tracking-wider rounded-full bg-emerald-500/20 text-emerald-700 dark:text-emerald-300">
                                                <CheckCircle2 className="w-3 h-3" /> Connected
                                            </span>
                                        ) : (
                                            <span className="inline-flex px-2 py-0.5 text-[10px] font-bold uppercase tracking-wider rounded-full bg-slate-500/20 text-content-muted">
                                                Not connected
                                            </span>
                                        )}
                                    </div>
                                    <p className="text-xs text-content-muted mt-1">{p.description}</p>

                                    {p.key === 'shopify' && (
                                        <div className="flex flex-wrap gap-1.5 mt-2">
                                            <span className="px-1.5 py-0.5 rounded text-[10px] font-semibold bg-slate-500/15 text-slate-600 dark:text-slate-300">App · Coming soon</span>
                                            <span className="px-1.5 py-0.5 rounded text-[10px] font-semibold bg-amber-500/15 text-amber-700 dark:text-amber-300">Admin token · Advanced</span>
                                            <span className="px-1.5 py-0.5 rounded text-[10px] font-semibold bg-emerald-500/15 text-emerald-700 dark:text-emerald-300">Webhook · Recommended</span>
                                        </div>
                                    )}

                                    {conn && (
                                        <dl className="mt-3 grid grid-cols-2 gap-y-1 text-xs text-content-muted">
                                            <dt>Synced products</dt>
                                            <dd className="text-content-muted text-right tabular-nums">{conn.synced_products_count ?? 0}</dd>
                                            <dt>Synced orders</dt>
                                            <dd className="text-content-muted text-right tabular-nums">{conn.synced_orders_count ?? 0}</dd>
                                            {conn.last_synced_at && (
                                                <>
                                                    <dt>Last sync</dt>
                                                    <dd className="text-content-muted text-right">{new Date(conn.last_synced_at).toLocaleString()}</dd>
                                                </>
                                            )}
                                        </dl>
                                    )}

                                    <div className="mt-4">
                                        {connected ? (
                                            <Link
                                                href={store ? `/dashboard/integrations/${p.key}` : '#'}
                                                className="inline-flex items-center gap-1.5 px-3 py-1.5 text-xs font-medium rounded-lg bg-surface-3 border border-line text-content hover:bg-content/10 transition"
                                            >
                                                <Plug className="w-3.5 h-3.5" /> Configure
                                            </Link>
                                        ) : (
                                            <Link
                                                href={store ? `/dashboard/integrations/${p.key}` : '#'}
                                                className="inline-flex items-center gap-1.5 px-3 py-1.5 text-xs font-semibold rounded-lg bg-indigo-600 text-white hover:bg-indigo-500 transition"
                                            >
                                                <ExternalLink className="w-3.5 h-3.5" /> Connect
                                            </Link>
                                        )}
                                    </div>
                                </div>
                            </div>
                        </div>
                    );
                })}
            </div>
        </SaasLayout>
    );
}
