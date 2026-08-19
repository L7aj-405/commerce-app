import { usePage, Link } from '@inertiajs/react';
import { Hand, Truck, ArrowRight } from 'lucide-react';
import SaasLayout from '@/Layouts/SaasLayout';
import OperationsNav from '@/Components/Departments/OperationsNav';
import OperationsFilterBar from '@/Components/Departments/OperationsFilterBar';
import OperationsTable from '@/Components/Departments/OperationsTable';
import useQueue from '@/Hooks/useQueue';
import useOperationsFilters from '@/Hooks/useOperationsFilters';
import { StatTiles, QueueToolbar, EmptyQueue } from '@/Components/Departments/QueueParts';

/**
 * Packed orders staged for handover. Carrier assignment stays on the existing
 * Dispatch board (DispatchService) — this is a warehouse-side visibility and
 * claim/release view, not a second place to assign couriers.
 */
export default function ReadyForDelivery({ orders = [], is_agency_context = false }) {
    const userId   = usePage().props.auth?.user?.id ?? null;
    const currency = orders[0]?.currency ?? 'MAD';

    const q = useQueue(orders, { userId });
    const f = useOperationsFilters(q.rows);

    const post = (order, url, data = {}) => q.submit(url, data, { key: q.keyOf(order) });

    return (
        <SaasLayout pageHeader={{
            title: 'Ready for delivery',
            subtitle: 'Packed orders staged and waiting for a carrier',
            breadcrumbs: [{ label: 'Dashboard', href: '/dashboard' }, { label: 'Operations' }, { label: 'Ready for delivery' }],
        }}>
            <OperationsNav current="ready-delivery" />

            <StatTiles tiles={[
                { label: 'Staged', value: f.filtered.length, icon: Truck, tone: 'blue' },
            ]} />

            <QueueToolbar
                scope={q.scope} onScope={q.setScope} counts={q.counts}
                search={q.search} onSearch={q.setSearch}
                placeholder="Search order or customer…"
            />
            <OperationsFilterBar f={f} showClient={is_agency_context} />

            {f.filtered.length === 0 ? (
                <EmptyQueue
                    title="Nothing staged"
                    hint="Packed orders land here the moment they're marked ready."
                />
            ) : (
                <OperationsTable
                    rows={f.filtered}
                    currency={currency}
                    showClientColumn={is_agency_context}
                    renderActions={(o) => {
                        const busy = q.isBusy(o);
                        const mine = o.assigned_to === userId;

                        return (
                            <div className="inline-flex items-center gap-1.5">
                                {! o.assigned_to && (
                                    <button
                                        disabled={busy}
                                        onClick={() => post(o, `/dashboard/departments/${o.type}/${o.id}/claim`)}
                                        className="inline-flex items-center gap-1 px-2.5 py-1.5 text-xs font-medium rounded-lg bg-surface-2 border border-line text-content hover:bg-surface-3 disabled:opacity-50 transition"
                                    >
                                        <Hand className="w-3.5 h-3.5" /> Claim
                                    </button>
                                )}
                                {mine && (
                                    <button
                                        disabled={busy}
                                        onClick={() => post(o, `/dashboard/departments/${o.type}/${o.id}/release`)}
                                        className="px-2.5 py-1.5 text-xs font-medium rounded-lg bg-surface-2 border border-line text-content-muted hover:text-content disabled:opacity-50 transition"
                                    >
                                        Release
                                    </button>
                                )}
                                <Link
                                    href="/dashboard/departments/dispatch"
                                    className="inline-flex items-center gap-1 text-xs font-medium text-indigo-600 dark:text-indigo-400 hover:underline"
                                >
                                    Assign carrier <ArrowRight className="w-3 h-3" />
                                </Link>
                            </div>
                        );
                    }}
                />
            )}
        </SaasLayout>
    );
}
