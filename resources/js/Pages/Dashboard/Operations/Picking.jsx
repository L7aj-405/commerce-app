import { usePage } from '@inertiajs/react';
import { Hand, Loader2, PlayCircle, PackageSearch } from 'lucide-react';
import SaasLayout from '@/Layouts/SaasLayout';
import OperationsNav from '@/Components/Departments/OperationsNav';
import OperationsFilterBar from '@/Components/Departments/OperationsFilterBar';
import OperationsTable from '@/Components/Departments/OperationsTable';
import useQueue from '@/Hooks/useQueue';
import useOperationsFilters from '@/Hooks/useOperationsFilters';
import { StatTiles, QueueToolbar, EmptyQueue } from '@/Components/Departments/QueueParts';

/** Orders ready to pick, plus those currently being picked. */
export default function Picking({ orders = [], is_agency_context = false }) {
    const userId   = usePage().props.auth?.user?.id ?? null;
    const currency = orders[0]?.currency ?? 'MAD';

    const q = useQueue(orders, { userId });
    const f = useOperationsFilters(q.rows);

    const post = (order, url, data = {}) => q.submit(url, data, { key: q.keyOf(order) });
    const move = (order, status) => post(order, `/dashboard/orders/${order.type}/${order.id}/status`, { status });

    const toPick  = f.filtered.filter((o) => o.status === 'ready_for_picking' || o.status === 'confirmed');
    const picking = f.filtered.filter((o) => o.status === 'picking' || o.status === 'in_progress');

    return (
        <SaasLayout pageHeader={{
            title: 'Picking Queue',
            subtitle: 'Supervisor view of all orders currently in picking status.',
            breadcrumbs: [{ label: 'Dashboard', href: '/dashboard' }, { label: 'Supervisor Queues' }, { label: 'Picking Queue' }],
        }}>
            <OperationsNav current="picking" />

            <StatTiles tiles={[
                { label: 'To pick', value: toPick.length,   icon: PackageSearch, tone: 'blue' },
                { label: 'Picking', value: picking.length,  icon: PackageSearch, tone: 'indigo' },
            ]} />

            <QueueToolbar
                scope={q.scope} onScope={q.setScope} counts={q.counts}
                search={q.search} onSearch={q.setSearch}
                placeholder="Search order or customer…"
            />
            <OperationsFilterBar f={f} showClient={is_agency_context} />

            {f.filtered.length === 0 ? (
                <EmptyQueue
                    title="Nothing to pick"
                    hint="Orders arrive here once confirmed and fully allocated to a warehouse."
                />
            ) : (
                <OperationsTable
                    rows={f.filtered}
                    currency={currency}
                    showClientColumn={is_agency_context}
                    renderActions={(o) => {
                        const busy = q.isBusy(o);
                        const mine = o.assigned_to === userId;

                        if (o.status === 'picking' || o.status === 'in_progress') {
                            return (
                                <div className="inline-flex items-center gap-1.5">
                                    {mine && (
                                        <button
                                            disabled={busy}
                                            onClick={() => post(o, `/dashboard/departments/${o.type}/${o.id}/release`)}
                                            className="px-2.5 py-1.5 text-xs font-medium rounded-lg bg-surface-2 border border-line text-content-muted hover:text-content disabled:opacity-50 transition"
                                        >
                                            Release
                                        </button>
                                    )}
                                    <button
                                        disabled={busy}
                                        onClick={() => move(o, 'packing')}
                                        className="inline-flex items-center gap-1 px-2.5 py-1.5 text-xs font-semibold rounded-lg bg-indigo-600 text-white hover:bg-indigo-500 disabled:opacity-40 disabled:cursor-not-allowed transition"
                                    >
                                        {busy ? <Loader2 className="w-3.5 h-3.5 animate-spin" /> : null}
                                        Move to packing
                                    </button>
                                </div>
                            );
                        }

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
                                <button
                                    disabled={busy}
                                    onClick={() => move(o, 'picking')}
                                    className="inline-flex items-center gap-1 px-2.5 py-1.5 text-xs font-semibold rounded-lg bg-indigo-600 text-white hover:bg-indigo-500 disabled:opacity-40 transition"
                                >
                                    {busy ? <Loader2 className="w-3.5 h-3.5 animate-spin" /> : <PlayCircle className="w-3.5 h-3.5" />}
                                    Start picking
                                </button>
                            </div>
                        );
                    }}
                />
            )}
        </SaasLayout>
    );
}
