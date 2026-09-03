import { useState, useMemo } from 'react';
import { usePage } from '@inertiajs/react';
import {
    Package, PackageCheck, Loader2, Hand, Clock, Check, Boxes,
    ClipboardList, UserCheck, PlayCircle, MapPin,
} from 'lucide-react';
import SaasLayout from '@/Layouts/SaasLayout';
import DepartmentNav from '@/Components/Departments/DepartmentNav';
import useQueue from '@/Hooks/useQueue';
import {
    StatTiles, SourceBadge, QueueToolbar, AgentWorkload, EmptyQueue,
    fmtMoney, timeAgo, ageTone,
} from '@/Components/Departments/QueueParts';

/**
 * Pick & pack bench — confirmed online orders and delivery-bound POS orders in
 * one queue, because the physical work is identical for both.
 *
 * The item checklist is deliberately local, throwaway state: it is a picker's
 * scratchpad for the basket in front of them, not a record worth persisting.
 * What gets recorded is the outcome — the order moving to 'Ready for delivery'.
 */

export default function Packing({ store, orders = [], agents = [], stats = {}, departments = [], can_print_pick_ticket: canPrintPickTicket = false }) {
    const currency = store?.currency ?? 'MAD';
    const userId   = usePage().props.auth?.user?.id ?? null;

    const q = useQueue(orders, { userId });

    const visibleOnlineIds = q.rows.filter((o) => o.type === 'online').map((o) => o.id);
    const bulkTicketUrl = visibleOnlineIds.length > 0
        ? `/dashboard/orders/pick-pack-tickets?${visibleOnlineIds.map((id) => `ids[]=${encodeURIComponent(id)}`).join('&')}`
        : null;
    const [taking, setTaking]   = useState(false);
    const [checked, setChecked] = useState({});   // `${key}:${index}` → bool

    const post = (order, url, data = {}) => q.submit(url, data, { key: q.keyOf(order) });

    const takeNext = () => {
        setTaking(true);
        q.submit('/dashboard/departments/take-next/fulfillment', {}, { onFinish: () => setTaking(false) });
    };

    const move = (order, status) =>
        post(order, `/dashboard/orders/${order.type}/${order.id}/status`, { status });

    const toggle = (key, i) => setChecked((c) => ({ ...c, [`${key}:${i}`]: ! c[`${key}:${i}`] }));

    const pickedCount = (key, items) =>
        items.reduce((n, _, i) => n + (checked[`${key}:${i}`] ? 1 : 0), 0);

    return (
        <SaasLayout pageHeader={{
            title: 'Pick & Pack Workbench',
            subtitle: 'Worker screen for picking, packing, and moving orders through warehouse steps.',
            breadcrumbs: [{ label: 'Dashboard', href: '/dashboard' }, { label: 'Pick & Pack Workbench' }],
        }}>
            <DepartmentNav departments={departments} current="packing" />

            <StatTiles tiles={[
                { label: 'Waiting stock', value: stats.waiting_stock ?? 0, icon: ClipboardList, tone: 'amber' },
                { label: 'To pick',       value: stats.to_pick ?? 0,       icon: ClipboardList, tone: 'blue' },
                { label: 'Picking',       value: stats.picking ?? 0,       icon: Package,       tone: 'indigo' },
                { label: 'Packing',       value: stats.packing ?? 0,       icon: PackageCheck,  tone: 'emerald' },
                { label: 'Assigned to me', value: stats.mine ?? 0,    icon: UserCheck,     tone: 'blue' },
                { label: 'Units to pick',  value: stats.units ?? 0,   icon: Boxes,         tone: 'slate' },
            ]} />

            <div className="grid grid-cols-1 xl:grid-cols-[1fr_280px] gap-5 items-start">
                <div className="min-w-0">
                    <QueueToolbar
                        scope={q.scope} onScope={q.setScope} counts={q.counts}
                        search={q.search} onSearch={q.setSearch}
                        placeholder="Search order or customer…"
                        extra={
                            <div className="flex flex-wrap items-center gap-2">
                                <div className="flex items-center gap-1 p-1 rounded-[var(--radius-button)] bg-surface-2 border border-line">
                                    {[
                                        { value: 'all',    label: 'All sources' },
                                        { value: 'online', label: 'Online' },
                                        { value: 'pos',    label: 'POS delivery' },
                                    ].map((s) => (
                                        <button
                                            key={s.value}
                                            onClick={() => q.setSource(s.value)}
                                            className={[
                                                'px-3 py-1.5 text-sm font-medium rounded-[var(--radius-button)] transition',
                                                q.source === s.value
                                                    ? 'bg-surface text-content border border-line shadow-sm'
                                                    : 'text-content-muted hover:text-content hover:bg-surface-3 border border-transparent',
                                            ].join(' ')}
                                        >
                                            {s.label}
                                        </button>
                                    ))}
                                </div>
                                {canPrintPickTicket && bulkTicketUrl && (
                                    <a
                                        href={bulkTicketUrl}
                                        target="_blank"
                                        rel="noopener"
                                        className="inline-flex items-center gap-1.5 px-3 py-1.5 text-sm font-medium rounded-[var(--radius-button)] bg-surface-2 border border-line text-content hover:bg-surface-3 transition"
                                    >
                                        <ClipboardList className="w-4 h-4" /> Print tickets ({visibleOnlineIds.length})
                                    </a>
                                )}
                            </div>
                        }
                    />

                    {q.rows.length === 0 ? (
                        <EmptyQueue
                            title="Nothing to pack"
                            hint="Orders arrive here once the confirmation desk approves them."
                        />
                    ) : (
                        <ul className="space-y-3">
                            {q.rows.map((o) => {
                                const key     = q.keyOf(o);
                                const busy    = q.isBusy(o);
                                const mine    = o.assigned_to === userId;
                                const items   = o.items ?? [];
                                const picked  = pickedCount(key, items);
                                const allDone = items.length > 0 && picked === items.length;
                                const waitingStock = o.status === 'waiting_for_stock';
                                const readyToPick = o.status === 'ready_for_picking' || o.status === 'confirmed';
                                const picking = o.status === 'picking' || o.status === 'in_progress';
                                const packing = o.status === 'packing';

                                return (
                                    <li
                                        key={key}
                                        className={[
                                            'bg-surface border rounded-[var(--radius-card)] overflow-hidden transition',
                                            mine ? 'border-primary/50 ring-1 ring-primary/20' : 'border-line',
                                        ].join(' ')}
                                    >
                                        <div className="flex flex-wrap items-start justify-between gap-3 p-4 pb-3">
                                            <div className="min-w-0">
                                                <div className="flex items-center gap-2">
                                                    <SourceBadge source={o.source} label={o.source_label} />
                                                    <span className="font-mono text-xs text-content-muted">{o.reference}</span>
                                                    <span className={`inline-flex items-center gap-1 text-[11px] ${ageTone(o.created_at)}`}>
                                                        <Clock className="w-3 h-3" /> {timeAgo(o.created_at)}
                                                    </span>
                                                </div>
                                                <h3 className="mt-1.5 text-sm font-semibold text-content truncate">
                                                    {o.customer_name || 'Walk-in customer'}
                                                </h3>
                                                {o.delivery_address && (
                                                    <p className="mt-0.5 inline-flex items-start gap-1.5 text-xs text-content-muted">
                                                        <MapPin className="w-3.5 h-3.5 mt-px shrink-0" />
                                                        <span className="line-clamp-1">{o.delivery_address}</span>
                                                    </p>
                                                )}
                                                {o.allocation?.warehouse_name && (
                                                    <p className="mt-1 text-[11px] text-content-muted">
                                                        Warehouse: <span className="font-medium text-content">{o.allocation.warehouse_name}</span>
                                                        {o.allocation.shortage_quantity > 0 && ` · waiting ${o.allocation.shortage_quantity} unit(s)`}
                                                    </p>
                                                )}
                                            </div>

                                            <div className="text-right shrink-0">
                                                <div className="text-base font-bold tabular-nums text-content">
                                                    {fmtMoney(o.total, currency)}
                                                </div>
                                                <div className="mt-0.5 text-[11px] text-content-muted">
                                                    {picked}/{items.length} picked
                                                    {o.assigned_to && ` · ${mine ? 'yours' : o.assignee_name ?? 'taken'}`}
                                                </div>
                                            </div>
                                        </div>

                                        {/* Picking checklist */}
                                        <div className="px-4">
                                            <ul className="rounded-[var(--radius-button)] border border-line divide-y divide-line/60 overflow-hidden">
                                                {items.length === 0 && (
                                                    <li className="px-3 py-2.5 text-xs text-content-muted">No line items recorded.</li>
                                                )}
                                                {items.map((it, i) => {
                                                    const on = Boolean(checked[`${key}:${i}`]);
                                                    return (
                                                        <li key={i}>
                                                            <button
                                                                onClick={() => toggle(key, i)}
                                                                className={[
                                                                    'w-full flex items-center gap-3 px-3 py-2.5 text-left transition',
                                                                    on ? 'bg-success-soft' : 'bg-surface-2/40 hover:bg-surface-3/60',
                                                                ].join(' ')}
                                                            >
                                                                <span className={[
                                                                    'shrink-0 inline-flex items-center justify-center w-5 h-5 rounded border transition',
                                                                    on
                                                                        ? 'bg-success border-success text-white'
                                                                        : 'border-line bg-surface',
                                                                ].join(' ')}>
                                                                    {on && <Check className="w-3.5 h-3.5" strokeWidth={3} />}
                                                                </span>
                                                                <span className="shrink-0 inline-flex items-center justify-center min-w-7 h-6 px-1.5 rounded-md bg-surface border border-line text-[11px] font-semibold tabular-nums text-content-muted">
                                                                    {it.quantity}×
                                                                </span>
                                                                <span className="min-w-0 flex-1">
                                                                    <span className={`block text-sm truncate ${on ? 'text-content-muted line-through' : 'text-content'}`}>
                                                                        {it.name}
                                                                    </span>
                                                                    {it.sku && (
                                                                        <span className="block text-[11px] font-mono text-content-muted truncate">{it.sku}</span>
                                                                    )}
                                                                </span>
                                                            </button>
                                                        </li>
                                                    );
                                                })}
                                            </ul>
                                        </div>

                                        <div className="flex flex-wrap items-center gap-2 p-4 pt-3">
                                            {! o.assigned_to && ! waitingStock && (
                                                <button
                                                    disabled={busy}
                                                    onClick={() => post(o, `/dashboard/departments/${o.type}/${o.id}/claim`)}
                                                    className="inline-flex items-center gap-1.5 px-3 py-2 text-sm font-medium rounded-[var(--radius-button)] bg-surface-2 border border-line text-content hover:bg-surface-3 disabled:opacity-50 transition"
                                                >
                                                    <Hand className="w-4 h-4" /> Claim
                                                </button>
                                            )}
                                            {mine && (
                                                <button
                                                    disabled={busy}
                                                    onClick={() => post(o, `/dashboard/departments/${o.type}/${o.id}/release`)}
                                                    className="inline-flex items-center gap-1.5 px-3 py-2 text-sm font-medium rounded-[var(--radius-button)] bg-surface-2 border border-line text-content-muted hover:text-content disabled:opacity-50 transition"
                                                >
                                                    Release
                                                </button>
                                            )}

                                            {canPrintPickTicket && o.type === 'online' && (
                                                <a
                                                    href={`/dashboard/orders/online/${o.id}/pick-pack-ticket`}
                                                    target="_blank"
                                                    rel="noopener"
                                                    className="inline-flex items-center gap-1.5 px-3 py-2 text-sm font-medium rounded-[var(--radius-button)] bg-surface-2 border border-line text-content hover:bg-surface-3 transition"
                                                >
                                                    <ClipboardList className="w-4 h-4" /> Print ticket
                                                </a>
                                            )}

                                            <div className="ml-auto flex items-center gap-2">
                                                {waitingStock && (
                                                    <span className="inline-flex items-center gap-1.5 px-3 py-2 text-sm font-medium rounded-[var(--radius-button)] bg-warning-soft text-warning">
                                                        {/* Never hardcode "Waiting for transfer" here — that label is only
                                                            correct once a real transfer exists and is actually in transit.
                                                            See App\Support\WaitingStockState. */}
                                                        {o.allocation?.waiting_state_label ?? 'Waiting for stock'}
                                                    </span>
                                                )}
                                                {readyToPick && (
                                                    <button
                                                        disabled={busy}
                                                        onClick={() => move(o, 'picking')}
                                                        className="inline-flex items-center gap-1.5 px-3.5 py-2 text-sm font-semibold rounded-[var(--radius-button)] bg-primary text-primary-contrast hover:bg-primary-strong disabled:opacity-40 transition"
                                                    >
                                                        {busy ? <Loader2 className="w-4 h-4 animate-spin" /> : <PlayCircle className="w-4 h-4" />}
                                                        Start picking
                                                    </button>
                                                )}
                                                {picking && (
                                                    <button
                                                        disabled={busy || ! allDone}
                                                        onClick={() => move(o, 'packing')}
                                                        title={allDone ? undefined : 'Tick every line before moving to packing'}
                                                        className="inline-flex items-center gap-1.5 px-3.5 py-2 text-sm font-semibold rounded-[var(--radius-button)] bg-primary text-primary-contrast hover:bg-primary-strong disabled:opacity-40 disabled:cursor-not-allowed transition"
                                                    >
                                                        {busy ? <Loader2 className="w-4 h-4 animate-spin" /> : <Package className="w-4 h-4" />}
                                                        Move to packing
                                                    </button>
                                                )}
                                                {packing && (
                                                    <button
                                                        disabled={busy || ! allDone}
                                                        onClick={() => move(o, 'ready_for_delivery')}
                                                        title={allDone ? undefined : 'Tick every line before marking it packed'}
                                                        className="inline-flex items-center gap-1.5 px-3.5 py-2 text-sm font-semibold rounded-[var(--radius-button)] bg-success text-white hover:brightness-90 disabled:opacity-40 disabled:cursor-not-allowed transition"
                                                    >
                                                        {busy ? <Loader2 className="w-4 h-4 animate-spin" /> : <PackageCheck className="w-4 h-4" />}
                                                        Packed & ready
                                                    </button>
                                                )}
                                            </div>
                                        </div>
                                    </li>
                                );
                            })}
                        </ul>
                    )}
                </div>

                <AgentWorkload
                    agents={agents}
                    title="Packers"
                    onTakeNext={takeNext}
                    taking={taking}
                    available={q.counts.unassigned}
                />
            </div>
        </SaasLayout>
    );
}
