import { Link, router } from '@inertiajs/react';
import { Plus, Store as StoreIcon, Users, Settings as SettingsIcon, CheckCircle2 } from 'lucide-react';
import SaasLayout from '@/Layouts/SaasLayout';
import EmptyState from '@/Components/EmptyState';

const TYPE_LABELS = {
    retail: 'Retail',
    restaurant: 'Restaurant',
    fashion: 'Fashion',
    electronics: 'Electronics',
    grocery: 'Grocery',
    other: 'Other',
};

export default function Index({ stores = [], currentStoreId }) {
    const switchTo = (id) => {
        if (id === currentStoreId) return;
        router.post('/dashboard/stores/switch', { store_id: id });
    };

    return (
        <SaasLayout pageHeader={{
            title: 'My stores',
            subtitle: 'Switch between stores you own or manage',
            breadcrumbs: [{ label: 'Dashboard', href: '/dashboard' }, { label: 'Stores' }],
            actions: (
                <Link
                    href="/dashboard/stores/create"
                    className="inline-flex items-center gap-1.5 px-3 py-2 text-sm font-semibold rounded-lg bg-indigo-600 text-white hover:bg-indigo-500 transition"
                >
                    <Plus className="w-4 h-4" /> Add store
                </Link>
            ),
        }}>
            {stores.length === 0 ? (
                <div className="bg-surface-2 border border-line rounded-xl">
                    <EmptyState
                        icon={StoreIcon}
                        title="You don't have any stores yet"
                        description="Create your first store to start ringing up sales."
                        action={
                            <Link href="/dashboard/stores/create" className="inline-flex items-center gap-1.5 px-4 py-2 text-sm font-semibold rounded-lg bg-indigo-600 text-white hover:bg-indigo-500">
                                <Plus className="w-4 h-4" /> Create store
                            </Link>
                        }
                    />
                </div>
            ) : (
                <div className="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-4">
                    {stores.map((s) => {
                        const isCurrent = s.id === currentStoreId;
                        return (
                            <div
                                key={s.id}
                                className={`bg-surface-2 border rounded-xl p-5 transition ${
                                    isCurrent ? 'border-indigo-500/40 ring-1 ring-indigo-500/20' : 'border-line hover:border-line'
                                }`}
                            >
                                <div className="flex items-start justify-between gap-3 mb-3">
                                    <div className="w-10 h-10 rounded-lg bg-gradient-to-br from-indigo-500 to-indigo-700 text-white font-bold flex items-center justify-center text-sm">
                                        {(s.name ?? '?').slice(0, 1).toUpperCase()}
                                    </div>
                                    {isCurrent && (
                                        <span className="inline-flex items-center gap-1 px-2 py-0.5 text-[10px] font-bold uppercase tracking-wider rounded-full bg-indigo-500/20 text-indigo-700 dark:text-indigo-300">
                                            <CheckCircle2 className="w-3 h-3" /> Active
                                        </span>
                                    )}
                                </div>

                                <h3 className="text-base font-semibold text-content truncate">{s.name}</h3>
                                <div className="flex items-center gap-1.5 mt-0.5">
                                    {s.type && (
                                        <span className="px-1.5 py-0.5 rounded text-[10px] font-bold uppercase tracking-wide bg-indigo-500/15 text-indigo-600 dark:text-indigo-300">
                                            {s.type}
                                        </span>
                                    )}
                                    <p className="text-xs text-content-muted">{TYPE_LABELS[s.business_type] ?? '—'}</p>
                                </div>

                                <dl className="mt-4 space-y-1.5 text-xs">
                                    <div className="flex items-center justify-between text-content-muted">
                                        <dt>Country</dt>
                                        <dd className="text-content">{s.country ?? '—'}</dd>
                                    </div>
                                    <div className="flex items-center justify-between text-content-muted">
                                        <dt>Currency</dt>
                                        <dd className="text-content">{s.currency ?? '—'}</dd>
                                    </div>
                                    <div className="flex items-center justify-between text-content-muted">
                                        <dt className="flex items-center gap-1"><Users className="w-3 h-3" /> Members</dt>
                                        <dd className="text-content">{s.member_count ?? 0}</dd>
                                    </div>
                                </dl>

                                <div className="mt-4 flex items-center gap-2">
                                    {! isCurrent && (
                                        <button
                                            type="button"
                                            onClick={() => switchTo(s.id)}
                                            className="flex-1 inline-flex items-center justify-center gap-1.5 px-3 py-2 text-xs font-semibold rounded-lg bg-indigo-600 text-white hover:bg-indigo-500 transition"
                                        >
                                            Switch to this store
                                        </button>
                                    )}
                                    <Link
                                        href="/dashboard/settings"
                                        className="inline-flex items-center gap-1 px-2.5 py-2 text-xs rounded-lg border border-line text-content-muted hover:bg-surface-3 hover:text-content"
                                        title="Settings"
                                    >
                                        <SettingsIcon className="w-3.5 h-3.5" />
                                    </Link>
                                </div>
                            </div>
                        );
                    })}
                </div>
            )}
        </SaasLayout>
    );
}
