import { Link } from '@inertiajs/react';
import { Warehouse, Plus, MapPin, Edit, Star, Building2 } from 'lucide-react';
import SaasLayout from '@/Layouts/SaasLayout';
import EmptyState from '@/Components/EmptyState';

const TYPE_LABEL = { merchant: 'Merchant', agency: 'Agency', client: 'Client' };

/**
 * Owner vs. operator organization — the two are often the same org (a
 * merchant's own warehouse), collapsed to one line in that case; shown
 * separately when they differ (e.g. an agency-operated client warehouse).
 * Gracefully renders nothing when the controller hasn't loaded either.
 */
function OwnershipLine({ owner, operator }) {
    if (! owner && ! operator) return null;

    const sameOrg = owner && operator && owner.id === operator.id;

    return (
        <div className="mt-3 flex items-start gap-2 text-xs text-content-muted">
            <Building2 className="w-3.5 h-3.5 flex-shrink-0 mt-0.5" />
            {sameOrg ? (
                <span>{owner.name} <span className="text-content-muted/70">({TYPE_LABEL[owner.type] ?? owner.type})</span></span>
            ) : (
                <span className="space-x-1">
                    {owner && <span>Owner: {owner.name} <span className="text-content-muted/70">({TYPE_LABEL[owner.type] ?? owner.type})</span></span>}
                    {owner && operator && <span>·</span>}
                    {operator && <span>Operator: {operator.name} <span className="text-content-muted/70">({TYPE_LABEL[operator.type] ?? operator.type})</span></span>}
                </span>
            )}
        </div>
    );
}

export default function Index({ warehouses = [] }) {
    return (
        <SaasLayout pageHeader={{
            title: 'Warehouses',
            subtitle: 'Stock locations across your business',
            breadcrumbs: [{ label: 'Dashboard', href: '/dashboard' }, { label: 'Warehouses' }],
            actions: (
                <Link href="/dashboard/warehouses/create" className="inline-flex items-center gap-1.5 px-3 py-2 text-sm font-semibold rounded-lg bg-primary text-white hover:bg-primary-strong">
                    <Plus className="w-4 h-4" /> Add warehouse
                </Link>
            ),
        }}>
            {warehouses.length === 0 ? (
                <div className="bg-surface-2 border border-line rounded-xl">
                    <EmptyState
                        icon={Warehouse}
                        title="No warehouses yet"
                        description="Create your first warehouse to track stock by location."
                        action={
                            <Link href="/dashboard/warehouses/create" className="inline-flex items-center gap-1.5 px-4 py-2 text-sm font-semibold rounded-lg bg-primary text-white hover:bg-primary-strong">
                                <Plus className="w-4 h-4" /> Add warehouse
                            </Link>
                        }
                    />
                </div>
            ) : (
                <div className="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-4">
                    {warehouses.map((w) => (
                        <div key={w.id} className="bg-surface-2 border border-line rounded-xl p-5 hover:border-line transition">
                            <div className="flex items-start justify-between gap-2 mb-3">
                                <div className="w-10 h-10 rounded-lg bg-primary-soft text-primary dark:text-primary flex items-center justify-center">
                                    <Warehouse className="w-5 h-5" />
                                </div>
                                {w.is_default && (
                                    <span className="inline-flex items-center gap-1 px-2 py-0.5 text-[10px] font-bold uppercase tracking-wider rounded-full bg-amber-500/20 text-amber-700 dark:text-amber-300">
                                        <Star className="w-3 h-3" /> Default
                                    </span>
                                )}
                            </div>

                            <h3 className="text-base font-semibold text-content truncate">{w.name}</h3>
                            {w.location && <p className="text-xs text-content-muted mt-0.5">{w.location}</p>}

                            {(w.address || w.city || w.country) && (
                                <div className="mt-3 flex items-start gap-2 text-xs text-content-muted">
                                    <MapPin className="w-3.5 h-3.5 flex-shrink-0 mt-0.5" />
                                    <span>{[w.address, w.city, w.country].filter(Boolean).join(', ')}</span>
                                </div>
                            )}

                            <OwnershipLine owner={w.owner_organization} operator={w.operator_organization} />

                            <div className="mt-4 flex items-center justify-between">
                                <span className={`text-[11px] uppercase tracking-wider font-bold ${w.is_active ? 'text-emerald-600 dark:text-emerald-400' : 'text-content-muted'}`}>
                                    {w.is_active ? 'Active' : 'Inactive'}
                                </span>
                                {w.can_manage !== false && (
                                    <Link
                                        href={`/dashboard/warehouses/${w.id}/edit`}
                                        className="inline-flex items-center gap-1 text-xs text-primary dark:text-primary hover:text-primary-strong dark:text-primary"
                                    >
                                        <Edit className="w-3 h-3" /> Edit
                                    </Link>
                                )}
                            </div>
                        </div>
                    ))}
                </div>
            )}
        </SaasLayout>
    );
}
