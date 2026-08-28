import { useState, useEffect } from 'react';
import { usePage } from '@inertiajs/react';
import axios from 'axios';
import {
    Phone, Mail, Clock, CheckCircle2, XCircle, Loader2, Hand,
    Inbox, UserCheck, Timer, ListChecks, AlertTriangle, Lock, LogOut,
} from 'lucide-react';
import SaasLayout from '@/Layouts/SaasLayout';
import DepartmentNav from '@/Components/Departments/DepartmentNav';
import useQueue from '@/Hooks/useQueue';
import {
    StatTiles, SourceBadge, QueueToolbar, AgentWorkload, EmptyQueue,
    ReasonDialog, fmtMoney, timeAgo, ageTone,
} from '@/Components/Departments/QueueParts';

/**
 * Confirmation desk — the 'Pending confirmation' queue.
 *
 * Agents claim an order before contacting the customer so two people never ring
 * the same number; the claim is a locked write server-side, and losing the race
 * comes back as an error toast rather than a silent overwrite.
 */

const CANCEL_REASONS = [
    { value: 'Customer unreachable',       label: 'Customer unreachable' },
    { value: 'Customer declined',          label: 'Customer declined the order' },
    { value: 'Duplicate order',            label: 'Duplicate order' },
    { value: 'Out of stock',               label: 'Item out of stock' },
    { value: 'other',                      label: 'Other…' },
];

export default function Confirmation({ store, orders = [], agents = [], stats = {}, departments = [], cities = [] }) {
    const currency = store?.currency ?? 'MAD';
    const userId   = usePage().props.auth?.user?.id ?? null;

    // Opening the Confirmation Desk marks unseen new-order notifications
    // seen for this user — the ticket's own definition of "seen" for this page.
    useEffect(() => {
        axios.post('/dashboard/notifications/mark-seen', { context: 'confirmation_desk' }).catch(() => {});
    }, []);

    const q = useQueue(orders, { userId });
    const [cancelling, setCancelling] = useState(null);   // order awaiting a reason
    const [taking, setTaking]         = useState(false);
    const [cityByOrder, setCityByOrder] = useState({});
    const [addressByOrder, setAddressByOrder] = useState({});
    const [nameByOrder, setNameByOrder] = useState({});
    const [phoneByOrder, setPhoneByOrder] = useState({});

    const post = (order, url, data = {}) => q.submit(url, data, { key: q.keyOf(order) });

    const takeNext = () => {
        setTaking(true);
        q.submit('/dashboard/departments/take-next/confirmation', {}, {
            onFinish: () => setTaking(false),
        });
    };

    const move = (order, status, reason) => {
        const key = q.keyOf(order);
        const payload = { status, reason };

        if (status === 'confirmed') {
            payload.shipping_city_id = cityByOrder[key] || order.shipping_city_id || null;
            payload.shipping_address = addressByOrder[key] ?? order.delivery_address ?? '';
            payload.customer_name = nameByOrder[key] ?? order.customer_name ?? '';
            payload.customer_phone = phoneByOrder[key] ?? order.customer_phone ?? '';
        }

        return post(order, `/dashboard/orders/${order.type}/${order.id}/status`, payload);
    };

    return (
        <SaasLayout pageHeader={{
            title: 'Confirmation Desk',
            subtitle: 'Reach the customer, then confirm or cancel',
            breadcrumbs: [{ label: 'Dashboard', href: '/dashboard' }, { label: 'Confirmation Desk' }],
        }}>
            <DepartmentNav departments={departments} current="confirmation" />

            <StatTiles tiles={[
                { label: 'Waiting',       value: stats.waiting ?? 0, icon: Inbox,      tone: 'amber' },
                { label: 'Assigned to me', value: stats.mine ?? 0,   icon: UserCheck,  tone: 'indigo' },
                { label: 'In queue',      value: stats.total ?? 0,   icon: ListChecks, tone: 'slate' },
                { label: 'Longest wait',  value: timeAgo(stats.oldest), icon: Timer,   tone: 'slate', hint: 'oldest unconfirmed order' },
            ]} />

            <div className="grid grid-cols-1 xl:grid-cols-[1fr_280px] gap-5 items-start">
                <div className="min-w-0">
                    <QueueToolbar
                        scope={q.scope} onScope={q.setScope} counts={q.counts}
                        search={q.search} onSearch={q.setSearch}
                        placeholder="Search order, name or phone…"
                    />

                    {q.rows.length === 0 ? (
                        <EmptyQueue
                            title="Nothing to confirm"
                            hint="New online orders land here the moment they sync."
                        />
                    ) : (
                        <ul className="space-y-2.5">
                            {q.rows.map((o) => {
                                const busy  = q.isBusy(o);
                                const mine  = o.assigned_to === userId;
                                const taken = o.assigned_to && ! mine;

                                return (
                                    <li
                                        key={q.keyOf(o)}
                                        className={[
                                            'bg-surface border rounded-[var(--radius-card)] p-4 transition',
                                            mine ? 'border-primary/50 ring-1 ring-primary/20' : 'border-line',
                                        ].join(' ')}
                                    >
                                        <div className="flex flex-wrap items-start justify-between gap-3">
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

                                                <div className="mt-1 flex flex-wrap items-center gap-x-4 gap-y-1 text-xs text-content-muted">
                                                    {o.source === 'online' && o.connection_label && (
                                                        <span className="truncate max-w-[200px]">
                                                            {o.connection_label}
                                                            {o.external_order_number ? ` · #${o.external_order_number}` : ''}
                                                        </span>
                                                    )}
                                                    {o.customer_phone && (
                                                        <a href={`tel:${o.customer_phone}`} className="inline-flex items-center gap-1.5 hover:text-content transition">
                                                            <Phone className="w-3.5 h-3.5" /> {o.customer_phone}
                                                        </a>
                                                    )}
                                                    {o.customer_email && (
                                                        <span className="inline-flex items-center gap-1.5 truncate">
                                                            <Mail className="w-3.5 h-3.5" /> {o.customer_email}
                                                        </span>
                                                    )}
                                                    <span>{o.items?.length ?? 0} item(s)</span>
                                                </div>
                                            </div>

                                            <div className="text-right shrink-0">
                                                <div className="text-base font-bold tabular-nums text-content">
                                                    {fmtMoney(o.total, currency)}
                                                </div>
                                                {o.assigned_to && (
                                                    <div className={`mt-0.5 inline-flex items-center gap-1 text-[11px] ${mine ? 'text-primary' : 'text-content-muted'}`}>
                                                        <Lock className="w-3 h-3" />
                                                        {mine ? 'Claimed by you' : `Claimed by ${o.assignee_name ?? 'another agent'}`}
                                                    </div>
                                                )}
                                            </div>
                                        </div>

                                        {/* Line items, collapsed to a single readable strip */}
                                        {(o.items ?? []).length > 0 && (
                                            <p className="mt-2.5 text-xs text-content-muted truncate">
                                                {o.items.map((i) => `${i.quantity}× ${i.name}`).join(' · ')}
                                            </p>
                                        )}

                                        <div className="mt-3 grid grid-cols-1 md:grid-cols-2 gap-2">
                                            <input
                                                type="text"
                                                value={nameByOrder[q.keyOf(o)] ?? o.customer_name ?? ''}
                                                onChange={(e) => setNameByOrder((v) => ({ ...v, [q.keyOf(o)]: e.target.value }))}
                                                placeholder="Customer name"
                                                className="w-full rounded-[var(--radius-button)] border border-line bg-surface px-3 py-2 text-sm text-content placeholder:text-content-muted focus:outline-none focus:ring-2 focus:ring-primary/30"
                                            />
                                            <input
                                                type="text"
                                                value={phoneByOrder[q.keyOf(o)] ?? o.customer_phone ?? ''}
                                                onChange={(e) => setPhoneByOrder((v) => ({ ...v, [q.keyOf(o)]: e.target.value }))}
                                                placeholder="Customer phone"
                                                className="w-full rounded-[var(--radius-button)] border border-line bg-surface px-3 py-2 text-sm text-content placeholder:text-content-muted focus:outline-none focus:ring-2 focus:ring-primary/30"
                                            />
                                        </div>

                                        {/* Read-only reference: the address exactly as the platform sent it. */}
                                        {o.original_address?.has_address ? (
                                            <p className="mt-2 text-[11px] text-content-muted">
                                                Original: {[
                                                    o.original_address.address1,
                                                    o.original_address.address2,
                                                    o.original_address.city,
                                                    o.original_address.province,
                                                    o.original_address.country,
                                                ].filter(Boolean).join(', ') || '—'}
                                                {o.original_address.notes && ` · Note: ${o.original_address.notes}`}
                                            </p>
                                        ) : (
                                            <p className="mt-2 inline-flex items-center gap-1 text-[11px] text-warning">
                                                <AlertTriangle className="w-3 h-3" /> No customer address was provided by the platform.
                                            </p>
                                        )}

                                        <div className="mt-1.5 grid grid-cols-1 md:grid-cols-[1fr_220px] gap-2">
                                            <input
                                                type="text"
                                                value={addressByOrder[q.keyOf(o)] ?? o.delivery_address ?? ''}
                                                onChange={(e) => setAddressByOrder((v) => ({ ...v, [q.keyOf(o)]: e.target.value }))}
                                                placeholder="Confirmed delivery address"
                                                className="w-full rounded-[var(--radius-button)] border border-line bg-surface px-3 py-2 text-sm text-content placeholder:text-content-muted focus:outline-none focus:ring-2 focus:ring-primary/30"
                                            />
                                            <select
                                                value={cityByOrder[q.keyOf(o)] ?? o.suggested_city_id ?? ''}
                                                onChange={(e) => setCityByOrder((v) => ({ ...v, [q.keyOf(o)]: e.target.value }))}
                                                className="w-full rounded-[var(--radius-button)] border border-line bg-surface px-3 py-2 text-sm text-content focus:outline-none focus:ring-2 focus:ring-primary/30"
                                            >
                                                <option value="">Select city for allocation…</option>
                                                {cities.map((city) => (
                                                    <option key={city.id} value={city.id}>{city.name}</option>
                                                ))}
                                            </select>
                                        </div>
                                        {! o.city_recognized && o.raw_city_name && ! cityByOrder[q.keyOf(o)] && (
                                            <p className="mt-1 inline-flex items-center gap-1 text-[11px] text-warning">
                                                <AlertTriangle className="w-3 h-3" />
                                                Platform reported city "{o.raw_city_name}" — not in the city list. Select the matching city before confirming.
                                            </p>
                                        )}

                                        {! o.assigned_to && (
                                            <p className="mt-2 text-[11px] text-content-muted">
                                                Claim an order before confirming or cancelling it.
                                            </p>
                                        )}

                                        <div className="mt-3 flex flex-wrap items-center gap-2">
                                            {! o.assigned_to && (
                                                <button
                                                    disabled={busy}
                                                    onClick={() => post(o, `/dashboard/departments/${o.type}/${o.id}/claim`)}
                                                    className="inline-flex items-center gap-1.5 px-3 py-2 text-sm font-medium rounded-[var(--radius-button)] bg-surface-2 border border-line text-content hover:bg-surface-3 disabled:opacity-50 transition"
                                                >
                                                    <Hand className="w-4 h-4" /> Claim order
                                                </button>
                                            )}

                                            {mine && (
                                                <button
                                                    disabled={busy}
                                                    onClick={() => post(o, `/dashboard/departments/${o.type}/${o.id}/release`)}
                                                    className="inline-flex items-center gap-1.5 px-3 py-2 text-sm font-medium rounded-[var(--radius-button)] bg-surface-2 border border-line text-content-muted hover:text-content disabled:opacity-50 transition"
                                                >
                                                    <LogOut className="w-4 h-4" /> Release claim
                                                </button>
                                            )}

                                            <div className="ml-auto flex items-center gap-2">
                                                <button
                                                    disabled={busy || ! mine}
                                                    onClick={() => setCancelling(o)}
                                                    title={! mine ? (taken ? 'Claimed by another agent' : 'Claim this order before cancelling it') : undefined}
                                                    className="inline-flex items-center gap-1.5 px-3 py-2 text-sm font-semibold rounded-[var(--radius-button)] bg-surface border border-danger/40 text-danger hover:bg-danger-soft disabled:opacity-40 disabled:cursor-not-allowed transition"
                                                >
                                                    <XCircle className="w-4 h-4" /> Cancel order
                                                </button>
                                                <button
                                                    disabled={busy || ! mine}
                                                    onClick={() => move(o, 'confirmed')}
                                                    title={! mine ? (taken ? 'Claimed by another agent' : 'Claim this order before confirming it') : undefined}
                                                    className="inline-flex items-center gap-1.5 px-3.5 py-2 text-sm font-semibold rounded-[var(--radius-button)] bg-success text-white hover:brightness-90 disabled:opacity-40 disabled:cursor-not-allowed transition"
                                                >
                                                    {busy ? <Loader2 className="w-4 h-4 animate-spin" /> : <CheckCircle2 className="w-4 h-4" />}
                                                    Confirm order
                                                </button>
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
                    title="Confirmation agents"
                    onTakeNext={takeNext}
                    taking={taking}
                    available={q.counts.unassigned}
                />
            </div>

            <ReasonDialog
                open={Boolean(cancelling)}
                title="Cancel this order"
                description="Cancelling releases any stock committed to it. The reason is recorded on the audit trail."
                confirmLabel="Cancel order"
                presets={CANCEL_REASONS}
                onCancel={() => setCancelling(null)}
                onConfirm={(reason) => { move(cancelling, 'cancelled', reason); setCancelling(null); }}
            />
        </SaasLayout>
    );
}
