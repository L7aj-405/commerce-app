import { usePage } from '@inertiajs/react';
import { Loader2, PackageCheck } from 'lucide-react';
import SaasLayout from '@/Layouts/SaasLayout';
import OperationsNav from '@/Components/Departments/OperationsNav';
import OperationsFilterBar from '@/Components/Departments/OperationsFilterBar';
import OperationsTable from '@/Components/Departments/OperationsTable';
import useQueue from '@/Hooks/useQueue';
import useOperationsFilters from '@/Hooks/useOperationsFilters';
import { StatTiles, QueueToolbar, EmptyQueue } from '@/Components/Departments/QueueParts';

/** Picked orders being boxed up for handover — status = packing only. */
export default function Packing({ orders = [], is_agency_context = false }) {
    const userId   = usePage().props.auth?.user?.id ?? null;
    const currency = orders[0]?.currency ?? 'MAD';

    const q = useQueue(orders, { userId });
    const f = useOperationsFilters(q.rows);

    const post = (order, url, data = {}) => q.submit(url, data, { key: q.keyOf(order) });
    const move = (order, status) => post(order, `/dashboard/orders/${order.type}/${order.id}/status`, { status });

    return (
        <SaasLayout pageHeader={{
            title: 'Packing Queue',
            subtitle: 'Supervisor view of all orders currently in packing status.',
            breadcrumbs: [{ label: 'Dashboard', href: '/dashboard' }, { label: 'Supervisor Queues' }, { label: 'Packing Queue' }],
        }}>
            <OperationsNav current="packing" />

            <StatTiles tiles={[
                { label: 'Packing', value: f.filtered.length, icon: PackageCheck, tone: 'emerald' },
            ]} />

            <QueueToolbar
                scope={q.scope} onScope={q.setScope} counts={q.counts}
                search={q.search} onSearch={q.setSearch}
                placeholder="Search order or customer…"
            />
            <OperationsFilterBar f={f} showClient={is_agency_context} />

            {f.filtered.length === 0 ? (
                <EmptyQueue
                    title="Nothing to pack"
                    hint="Orders arrive here once every line has been picked."
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
                                {mine && (
                                    <button
                                        disabled={busy}
                                        onClick={() => post(o, `/dashboard/departments/${o.type}/${o.id}/release`)}
                                        className="px-2.5 py-1.5 text-xs font-medium rounded-[var(--radius-button)] bg-surface-2 border border-line text-content-muted hover:text-content disabled:opacity-50 transition"
                                    >
                                        Release
                                    </button>
                                )}
                                <button
                                    disabled={busy}
                                    onClick={() => move(o, 'ready_for_delivery')}
                                    className="inline-flex items-center gap-1 px-2.5 py-1.5 text-xs font-semibold rounded-[var(--radius-button)] bg-primary text-primary-contrast hover:bg-primary-strong disabled:opacity-40 disabled:cursor-not-allowed transition"
                                >
                                    {busy ? <Loader2 className="w-3.5 h-3.5 animate-spin" /> : <PackageCheck className="w-3.5 h-3.5" />}
                                    Packed &amp; ready
                                </button>
                            </div>
                        );
                    }}
                />
            )}
        </SaasLayout>
    );
}
