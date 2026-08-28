import { useState } from 'react';
import { usePage, Link } from '@inertiajs/react';
import { ClipboardList, RefreshCw, ArrowLeftRight, PackagePlus, Eye, Clock, MapPin, Warehouse, AlertTriangle, Loader2, Bug, ChevronDown, ChevronRight } from 'lucide-react';
import SaasLayout from '@/Layouts/SaasLayout';
import OperationsNav from '@/Components/Departments/OperationsNav';
import OperationsFilterBar from '@/Components/Departments/OperationsFilterBar';
import useQueue from '@/Hooks/useQueue';
import useOperationsFilters from '@/Hooks/useOperationsFilters';
import { StatTiles, SourceBadge, QueueToolbar, EmptyQueue, fmtMoney, timeAgo, ageTone } from '@/Components/Departments/QueueParts';

/**
 * Orders confirmed but blocked on missing stock — with the line-level
 * shortage detail (needed / available in target / missing / available
 * elsewhere) and the state a real transfer/restock decision is in, instead
 * of a bare "Waiting for transfer" that used to show even when no transfer
 * of any kind existed.
 *
 * State badges (App\Support\WaitingStockState — never guessed client-side):
 *   awaiting_restock   -> "Waiting for stock"
 *   restock_requested  -> "Restock requested"
 *   transfer_requested -> "Transfer requested"
 *   waiting_for_transfer -> "Waiting for transfer" (only once a transfer is actually in transit)
 *   ready_to_recheck   -> "Ready to recheck" (transfer received, recheck stock to finish)
 */

const STATE_TONE = {
    awaiting_restock: 'bg-amber-500/10 text-amber-700 dark:text-amber-300',
    restock_requested: 'bg-orange-500/10 text-orange-700 dark:text-orange-300',
    transfer_requested: 'bg-blue-500/10 text-blue-700 dark:text-blue-300',
    waiting_for_transfer: 'bg-primary-soft text-primary-strong dark:text-primary',
    ready_to_recheck: 'bg-emerald-500/10 text-emerald-700 dark:text-emerald-300',
};

export default function WaitingForStock({ orders = [], is_agency_context = false }) {
    const userId   = usePage().props.auth?.user?.id ?? null;
    const currency = orders[0]?.currency ?? 'MAD';

    const q = useQueue(orders, { userId });
    const f = useOperationsFilters(q.rows);
    const [debugKeys, setDebugKeys] = useState(() => new Set());
    const toggleDebug = (key) => setDebugKeys((prev) => {
        const next = new Set(prev);
        next.has(key) ? next.delete(key) : next.add(key);
        return next;
    });

    const post = (order, url, data = {}) => q.submit(url, data, { key: q.keyOf(order) });
    const recheck = (o) => post(o, `/dashboard/operations/waiting-stock/${o.type}/${o.id}/recheck`);
    const requestTransfer = (o, reservationId) => post(o, `/dashboard/operations/waiting-stock/${o.type}/${o.id}/request-transfer`, { reservation_id: reservationId });
    const markRestock = (o) => post(o, `/dashboard/operations/waiting-stock/${o.type}/${o.id}/restock-requested`);

    const stateCounts = f.filtered.reduce((acc, o) => {
        const state = o.shortage?.state ?? 'awaiting_restock';
        acc[state] = (acc[state] ?? 0) + 1;
        return acc;
    }, {});

    return (
        <SaasLayout pageHeader={{
            title: 'Waiting for stock',
            subtitle: 'Orders that cannot continue because sellable stock is missing.',
            breadcrumbs: [{ label: 'Dashboard', href: '/dashboard' }, { label: 'Supervisor Queues' }, { label: 'Waiting for stock' }],
        }}>
            <OperationsNav current="waiting-stock" />

            <StatTiles tiles={[
                { label: 'Waiting for stock',    value: stateCounts.awaiting_restock ?? 0,    icon: ClipboardList, tone: 'amber' },
                { label: 'Restock requested',    value: stateCounts.restock_requested ?? 0,   icon: PackagePlus,   tone: 'amber' },
                { label: 'Transfer requested',   value: stateCounts.transfer_requested ?? 0,  icon: ArrowLeftRight, tone: 'blue' },
                { label: 'Waiting for transfer', value: stateCounts.waiting_for_transfer ?? 0, icon: ArrowLeftRight, tone: 'indigo' },
                { label: 'Ready to recheck',     value: stateCounts.ready_to_recheck ?? 0,     icon: RefreshCw,     tone: 'emerald' },
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
                <ul className="space-y-2.5">
                    {f.filtered.map((o) => {
                        const key = q.keyOf(o);
                        const busy = q.isBusy(o);
                        const shortage = o.shortage;
                        const state = shortage?.state ?? 'awaiting_restock';
                        const stateLabel = shortage?.state_label ?? 'Waiting for stock';
                        const lines = shortage?.lines ?? [];
                        const canRequestTransfer = lines.some((l) => l.can_request_transfer);
                        const canMarkRestock = state === 'awaiting_restock' && !canRequestTransfer;

                        return (
                            <li key={key} className="bg-surface border border-line rounded-xl p-4">
                                <div className="flex flex-wrap items-start justify-between gap-3">
                                    <div className="min-w-0">
                                        <div className="flex items-center gap-2">
                                            <SourceBadge source={o.source} label={o.source_label} />
                                            <span className="font-mono text-xs text-content-muted">{o.reference}</span>
                                            <span className={`inline-flex items-center gap-1 text-[11px] ${ageTone(o.created_at)}`}>
                                                <Clock className="w-3 h-3" /> {timeAgo(o.created_at)}
                                            </span>
                                            <span className={`inline-flex items-center px-2 py-0.5 rounded-full text-[11px] font-semibold ${STATE_TONE[state] ?? STATE_TONE.awaiting_restock}`}>
                                                {stateLabel}
                                            </span>
                                        </div>

                                        <h3 className="mt-1.5 text-sm font-semibold text-content truncate">
                                            {o.customer_name || 'Walk-in customer'}
                                        </h3>

                                        <div className="mt-1 flex flex-wrap items-center gap-x-4 gap-y-1 text-xs text-content-muted">
                                            {o.shipping_city_name && (
                                                <span className="inline-flex items-center gap-1"><MapPin className="w-3.5 h-3.5" /> {o.shipping_city_name}</span>
                                            )}
                                            {shortage?.warehouse_name && (
                                                <span className="inline-flex items-center gap-1"><Warehouse className="w-3.5 h-3.5" /> {shortage.warehouse_name}</span>
                                            )}
                                        </div>

                                        {shortage?.notes && (
                                            <p className="mt-1.5 inline-flex items-start gap-1 text-[11px] text-amber-600 dark:text-amber-400">
                                                <AlertTriangle className="w-3.5 h-3.5 mt-px shrink-0" /> {shortage.notes}
                                            </p>
                                        )}
                                    </div>

                                    <div className="text-right shrink-0">
                                        <div className="text-base font-bold tabular-nums text-content">
                                            {fmtMoney(o.total, currency)}
                                        </div>
                                    </div>
                                </div>

                                {/* Shortage lines */}
                                {lines.length > 0 && (
                                    <div className="mt-3 overflow-x-auto rounded-lg border border-line">
                                        <table className="w-full text-xs">
                                            <thead>
                                                <tr className="text-left bg-surface-2 text-content-muted">
                                                    <th className="px-3 py-1.5 font-medium">Product</th>
                                                    <th className="px-3 py-1.5 font-medium text-right">Needed</th>
                                                    <th className="px-3 py-1.5 font-medium text-right">Available (target)</th>
                                                    <th className="px-3 py-1.5 font-medium text-right">Missing</th>
                                                    <th className="px-3 py-1.5 font-medium text-right">Elsewhere</th>
                                                    <th className="px-3 py-1.5 font-medium">Transfer</th>
                                                </tr>
                                            </thead>
                                            <tbody className="divide-y divide-line/60">
                                                {lines.map((line, i) => {
                                                    const unmapped = line.reservation_id === null;
                                                    return (
                                                        <tr key={line.reservation_id ?? `unmapped-${line.sku ?? i}`}>
                                                            <td className="px-3 py-1.5">
                                                                <div className="text-content">{line.name ?? '—'}</div>
                                                                {line.sku && <div className="font-mono text-[10px] text-content-muted">{line.sku}</div>}
                                                            </td>
                                                            <td className="px-3 py-1.5 text-right tabular-nums">{line.needed}</td>
                                                            <td className="px-3 py-1.5 text-right tabular-nums">{unmapped ? '—' : line.available_in_target}</td>
                                                            <td className="px-3 py-1.5 text-right tabular-nums text-amber-600 dark:text-amber-400 font-semibold">{line.missing}</td>
                                                            <td className="px-3 py-1.5 text-right tabular-nums">{unmapped ? '—' : line.available_elsewhere}</td>
                                                            <td className="px-3 py-1.5">
                                                                {unmapped ? (
                                                                    <span className="inline-flex items-center gap-1 text-[10px] font-semibold text-red-600 dark:text-red-400">
                                                                        <AlertTriangle className="w-3 h-3" /> Not linked to inventory
                                                                    </span>
                                                                ) : line.transfer ? (
                                                                    <span className="font-mono text-[10px] text-content-muted">{line.transfer.reference} ({line.transfer.status})</span>
                                                                ) : line.restock_requested_at ? (
                                                                    <span className="text-[10px] text-orange-600 dark:text-orange-400">Restock requested</span>
                                                                ) : (
                                                                    <span className="text-content-muted">—</span>
                                                                )}
                                                            </td>
                                                        </tr>
                                                    );
                                                })}
                                            </tbody>
                                        </table>
                                    </div>
                                )}

                                {debugKeys.has(key) && lines.length > 0 && (
                                    <div className="mt-2 rounded-lg border border-dashed border-line bg-surface-2/60 p-3 text-[11px] font-mono text-content-muted space-y-2">
                                        {lines.map((line, i) => (
                                            <div key={`debug-${line.reservation_id ?? `unmapped-${line.sku ?? i}`}`} className="space-y-0.5">
                                                <div className="text-content">{line.name ?? '—'} <span className="text-content-muted">({line.sku ?? 'no sku'})</span></div>
                                                <div>shortage_id={line.debug?.shortage_id ?? '—'} inventory_item_id={line.debug?.inventory_item_id ?? '—'}</div>
                                                <div>product_id={line.debug?.product_id ?? '—'} product_variant_id={line.debug?.product_variant_id ?? '—'}</div>
                                                <div>source_platform={line.debug?.source_platform ?? '—'} platform_connection_id={line.debug?.platform_connection_id ?? '—'}</div>
                                                <div>external_product_id={line.debug?.external_product_id ?? '—'} external_variant_id={line.debug?.external_variant_id ?? '—'}</div>
                                                <div>mapping_source={line.debug?.mapping_source ?? '—'}</div>
                                                <div>target_warehouse={line.debug?.target_warehouse_name ?? '—'} ({line.debug?.target_warehouse_id ?? '—'})</div>
                                                <div>on_hand={line.debug?.on_hand ?? 0} reserved={line.debug?.reserved ?? 0} transfer_reserved={line.debug?.transfer_reserved ?? 0} status={line.debug?.status ?? '—'}</div>
                                                <div>last_rechecked_at={line.debug?.last_rechecked_at ?? 'never'}</div>
                                                <div className={line.debug?.status === 'unmapped' ? 'text-red-500 dark:text-red-400 font-semibold' : ''}>
                                                    last_recheck_message={line.debug?.last_recheck_message ?? '—'}
                                                </div>
                                            </div>
                                        ))}
                                    </div>
                                )}

                                <div className="mt-3 flex flex-wrap items-center gap-2">
                                    <button
                                        disabled={busy}
                                        onClick={() => recheck(o)}
                                        className="inline-flex items-center gap-1.5 px-3 py-2 text-sm font-medium rounded-lg bg-surface-2 border border-line text-content hover:bg-surface-3 disabled:opacity-50 transition"
                                    >
                                        {busy ? <Loader2 className="w-3.5 h-3.5 animate-spin" /> : <RefreshCw className="w-3.5 h-3.5" />} Recheck stock
                                    </button>

                                    {lines.length > 0 && (
                                        <button
                                            onClick={() => toggleDebug(key)}
                                            className="inline-flex items-center gap-1 px-2 py-2 text-xs font-medium text-content-muted hover:text-content transition"
                                        >
                                            {debugKeys.has(key) ? <ChevronDown className="w-3.5 h-3.5" /> : <ChevronRight className="w-3.5 h-3.5" />}
                                            <Bug className="w-3.5 h-3.5" /> Diagnostics
                                        </button>
                                    )}

                                    {canRequestTransfer && (
                                        <button
                                            disabled={busy}
                                            onClick={() => requestTransfer(o, lines.find((l) => l.can_request_transfer)?.reservation_id)}
                                            className="inline-flex items-center gap-1.5 px-3 py-2 text-sm font-medium rounded-lg bg-surface-2 border border-line text-content hover:bg-surface-3 disabled:opacity-50 transition"
                                        >
                                            <ArrowLeftRight className="w-3.5 h-3.5" /> Request transfer
                                        </button>
                                    )}

                                    {canMarkRestock && (
                                        <button
                                            disabled={busy}
                                            onClick={() => markRestock(o)}
                                            className="inline-flex items-center gap-1.5 px-3 py-2 text-sm font-medium rounded-lg bg-surface-2 border border-line text-content hover:bg-surface-3 disabled:opacity-50 transition"
                                        >
                                            <PackagePlus className="w-3.5 h-3.5" /> Mark restock requested
                                        </button>
                                    )}

                                    <Link
                                        href={o.type === 'pos' ? `/dashboard/orders/${o.reference}` : `/dashboard/orders/online/${o.id}`}
                                        className="ml-auto inline-flex items-center gap-1.5 px-3 py-2 text-sm font-medium rounded-lg bg-surface-2 border border-line text-content hover:bg-surface-3 transition"
                                    >
                                        <Eye className="w-3.5 h-3.5" /> View order
                                    </Link>
                                </div>
                            </li>
                        );
                    })}
                </ul>
            )}
        </SaasLayout>
    );
}
