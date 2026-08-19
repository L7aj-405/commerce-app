import { usePage, Link } from '@inertiajs/react';
import { ClipboardList, ArrowRight } from 'lucide-react';
import SaasLayout from '@/Layouts/SaasLayout';
import OperationsNav from '@/Components/Departments/OperationsNav';
import OperationsFilterBar from '@/Components/Departments/OperationsFilterBar';
import OperationsTable from '@/Components/Departments/OperationsTable';
import useQueue from '@/Hooks/useQueue';
import useOperationsFilters from '@/Hooks/useOperationsFilters';
import { StatTiles, QueueToolbar, EmptyQueue } from '@/Components/Departments/QueueParts';

/**
 * Read-only monitoring queue: confirmed orders held back because a transfer is
 * still filling a shortage. These are intentionally NOT claimable here —
 * OrderAssignmentService blocks claiming a waiting_for_stock order, so the
 * only way out of this list is receiving the transfer (Transfer receiving
 * queue) or a manual cancellation from the order's own page.
 */
export default function WaitingForStock({ orders = [], is_agency_context = false }) {
    const userId   = usePage().props.auth?.user?.id ?? null;
    const currency = orders[0]?.currency ?? 'MAD';

    const q = useQueue(orders, { userId });
    const f = useOperationsFilters(q.rows);

    return (
        <SaasLayout pageHeader={{
            title: 'Waiting for stock',
            subtitle: 'Confirmed orders held until a transfer fills the shortage',
            breadcrumbs: [{ label: 'Dashboard', href: '/dashboard' }, { label: 'Operations' }, { label: 'Waiting for stock' }],
        }}>
            <OperationsNav current="waiting-stock" />

            <StatTiles tiles={[
                { label: 'Waiting for transfer', value: f.filtered.length, icon: ClipboardList, tone: 'amber' },
            ]} />

            <QueueToolbar
                scope={q.scope} onScope={q.setScope} counts={q.counts}
                search={q.search} onSearch={q.setSearch}
                placeholder="Search order or customer…"
            />
            <OperationsFilterBar f={f} showClient={is_agency_context} />

            {f.filtered.length === 0 ? (
                <EmptyQueue
                    title="Nothing waiting"
                    hint="Orders land here only when confirming them needed stock that isn't on hand yet."
                />
            ) : (
                <OperationsTable
                    rows={f.filtered}
                    currency={currency}
                    showClientColumn={is_agency_context}
                    renderActions={() => (
                        <Link
                            href="/dashboard/operations/transfers"
                            className="inline-flex items-center gap-1 text-xs font-medium text-indigo-600 dark:text-indigo-400 hover:underline"
                        >
                            Transfers <ArrowRight className="w-3 h-3" />
                        </Link>
                    )}
                />
            )}
        </SaasLayout>
    );
}
