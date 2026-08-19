import { useState, useMemo } from 'react';
import { usePage } from '@inertiajs/react';
import {
    Truck, User, Package, Loader2, CheckCircle2, XCircle, ExternalLink,
    MapPin, Clock, Send, FileText, Copy, Building2, Printer,
} from 'lucide-react';
import SaasLayout from '@/Layouts/SaasLayout';
import DepartmentNav from '@/Components/Departments/DepartmentNav';
import useQueue from '@/Hooks/useQueue';
import {
    StatTiles, SourceBadge, QueueToolbar, EmptyQueue, ReasonDialog,
    fmtMoney, timeAgo, ageTone,
} from '@/Components/Departments/QueueParts';

/**
 * Dispatch board — packed orders waiting for a carrier, and everything in flight.
 *
 * A failed delivery is not a dead end: it routes the order into the return flow,
 * where an inspector decides where the goods go. No stock moves here.
 */

const FAILURE_REASONS = [
    { value: 'refused',            label: 'Customer refused delivery' },
    { value: 'damaged_in_transit', label: 'Damaged in transit' },
    { value: 'other',              label: 'Other…' },
];

export default function Dispatch({ store, orders = [], agents = [], couriers = [], manifests = [], stats = {}, departments = [] }) {
    const currency = store?.currency ?? 'MAD';
    const userId   = usePage().props.auth?.user?.id ?? null;

    const q = useQueue(orders, { userId });
    const [assigning, setAssigning] = useState(null);   // order awaiting a carrier
    const [failing, setFailing]     = useState(null);   // shipment that failed

    const awaiting = useMemo(() => q.rows.filter((o) => ! o.shipment), [q.rows]);
    const inFlight = useMemo(() => q.rows.filter((o) => o.shipment), [q.rows]);

    const post = (order, url, data = {}) => q.submit(url, data, { key: q.keyOf(order) });

    return (
        <SaasLayout pageHeader={{
            title: 'Delivery & logistics',
            subtitle: 'Assign carriers, track shipments and confirm delivery',
            breadcrumbs: [{ label: 'Dashboard', href: '/dashboard' }, { label: 'Delivery' }],
        }}>
            <DepartmentNav departments={departments} current="dispatch" />

            <StatTiles tiles={[
                { label: 'Awaiting carrier', value: stats.awaiting ?? 0,  icon: Package,      tone: 'amber' },
                { label: 'In flight',        value: stats.in_flight ?? 0, icon: Truck,        tone: 'blue' },
                { label: 'Delivered',        value: stats.delivered ?? 0, icon: CheckCircle2, tone: 'emerald' },
                { label: 'Failed',           value: stats.failed ?? 0,    icon: XCircle,      tone: 'red' },
            ]} />

            {manifests.length > 0 && <ManifestBar manifests={manifests} />}

            <QueueToolbar
                scope={q.scope} onScope={q.setScope} counts={q.counts}
                search={q.search} onSearch={q.setSearch}
                placeholder="Search order, customer or tracking…"
            />

            {q.rows.length === 0 ? (
                <EmptyQueue
                    title="Nothing to dispatch"
                    hint="Packed orders appear here as soon as the warehouse marks them ready."
                />
            ) : (
                <div className="space-y-6">
                    <Section title="Awaiting carrier" count={awaiting.length}>
                        {awaiting.length === 0 ? (
                            <p className="px-4 py-6 text-center text-sm text-content-muted">
                                Everything packed has been handed over.
                            </p>
                        ) : (
                            <ul className="divide-y divide-line/60">
                                {awaiting.map((o) => (
                                    <li key={q.keyOf(o)} className="p-4 flex flex-wrap items-start justify-between gap-3">
                                        <OrderSummary order={o} currency={currency} />
                                        <button
                                            disabled={q.isBusy(o)}
                                            onClick={() => setAssigning(o)}
                                            className="inline-flex items-center gap-1.5 px-3.5 py-2 text-sm font-semibold rounded-lg bg-indigo-600 text-white hover:bg-indigo-500 disabled:opacity-40 transition"
                                        >
                                            {q.isBusy(o) ? <Loader2 className="w-4 h-4 animate-spin" /> : <Send className="w-4 h-4" />}
                                            Assign carrier
                                        </button>
                                    </li>
                                ))}
                            </ul>
                        )}
                    </Section>

                    <Section title="In flight" count={inFlight.length}>
                        {inFlight.length === 0 ? (
                            <p className="px-4 py-6 text-center text-sm text-content-muted">Nothing on the road.</p>
                        ) : (
                            <ul className="divide-y divide-line/60">
                                {inFlight.map((o) => {
                                    const s    = o.shipment;
                                    const busy = q.isBusy(o);
                                    const done = s.status === 'delivered' || s.status === 'failed';

                                    return (
                                        <li key={q.keyOf(o)} className="p-4">
                                            <div className="flex flex-wrap items-start justify-between gap-3">
                                                <OrderSummary order={o} currency={currency} />

                                                <div className="flex flex-col items-end gap-2">
                                                    <CarrierChip shipment={s} />
                                                    {! done && (
                                                        <div className="flex items-center gap-2">
                                                            <button
                                                                disabled={busy}
                                                                onClick={() => setFailing({ order: o, shipment: s })}
                                                                className="inline-flex items-center gap-1.5 px-3 py-2 text-sm font-semibold rounded-lg bg-surface border border-red-500/40 text-red-600 dark:text-red-400 hover:bg-red-500/10 disabled:opacity-40 transition"
                                                            >
                                                                <XCircle className="w-4 h-4" /> Failed
                                                            </button>
                                                            <button
                                                                disabled={busy}
                                                                onClick={() => post(o, `/dashboard/departments/shipments/${s.id}/delivered`)}
                                                                className="inline-flex items-center gap-1.5 px-3.5 py-2 text-sm font-semibold rounded-lg bg-emerald-600 text-white hover:bg-emerald-500 disabled:opacity-40 transition"
                                                            >
                                                                {busy ? <Loader2 className="w-4 h-4 animate-spin" /> : <CheckCircle2 className="w-4 h-4" />}
                                                                Delivered
                                                            </button>
                                                        </div>
                                                    )}
                                                </div>
                                            </div>
                                        </li>
                                    );
                                })}
                            </ul>
                        )}
                    </Section>
                </div>
            )}

            <AssignCarrierDialog
                order={assigning}
                couriers={couriers}
                agents={agents}
                onCancel={() => setAssigning(null)}
                onConfirm={(data) => {
                    post(assigning, `/dashboard/departments/${assigning.type}/${assigning.id}/carrier`, data);
                    setAssigning(null);
                }}
            />

            <ReasonDialog
                open={Boolean(failing)}
                title="Delivery failed"
                description="The order moves to the returns queue. No stock moves until an inspector sees the goods."
                confirmLabel="Record failure"
                presets={FAILURE_REASONS}
                onCancel={() => setFailing(null)}
                onConfirm={(reason) => {
                    post(failing.order, `/dashboard/departments/shipments/${failing.shipment.id}/failed`, { reason });
                    setFailing(null);
                }}
            />
        </SaasLayout>
    );
}

/* ------------------------------------------------------------------ */
/* Manifests — batch handover sheets                                   */
/* ------------------------------------------------------------------ */

function ManifestBar({ manifests }) {
    return (
        <section className="mb-4 bg-surface-2 border border-line rounded-xl p-3">
            <div className="flex items-center gap-2 mb-2.5 px-1">
                <FileText className="w-4 h-4 text-content-muted" />
                <h2 className="text-sm font-semibold text-content">Manifests</h2>
                <span className="text-[11px] text-content-muted">handover sheets for carrier batches</span>
            </div>
            <div className="flex flex-wrap gap-2">
                {manifests.map((m) => (
                    <a
                        key={m.reference}
                        href={`/dashboard/departments/manifests/${encodeURIComponent(m.reference)}`}
                        target="_blank"
                        rel="noopener"
                        className="group inline-flex items-center gap-2.5 pl-3 pr-2.5 py-2 rounded-lg bg-surface border border-line hover:border-indigo-500/50 transition"
                    >
                        <span className="min-w-0">
                            <span className="block font-mono text-xs text-content truncate">{m.reference}</span>
                            <span className="block text-[11px] text-content-muted">
                                {m.carrier} · {m.parcels} parcel{m.parcels === 1 ? '' : 's'}
                                {m.pending > 0 && ` · ${m.pending} in flight`}
                            </span>
                        </span>
                        <span className="shrink-0 inline-flex items-center gap-1 px-2 py-1 rounded-md bg-surface-2 border border-line text-[11px] font-semibold text-content-muted group-hover:text-content transition">
                            <Printer className="w-3.5 h-3.5" /> Print
                        </span>
                    </a>
                ))}
            </div>
        </section>
    );
}

function Section({ title, count, children }) {
    return (
        <section className="bg-surface-2 border border-line rounded-xl overflow-hidden">
            <header className="flex items-center justify-between gap-2 px-4 py-3 border-b border-line">
                <h2 className="text-sm font-semibold text-content">{title}</h2>
                <span className="min-w-5 px-1.5 rounded-full bg-surface border border-line text-[11px] tabular-nums text-content-muted">
                    {count}
                </span>
            </header>
            <div className="bg-surface">{children}</div>
        </section>
    );
}

function OrderSummary({ order, currency }) {
    return (
        <div className="min-w-0">
            <div className="flex items-center gap-2">
                <SourceBadge source={order.source} label={order.source_label} />
                <span className="font-mono text-xs text-content-muted">{order.reference}</span>
                <span className={`inline-flex items-center gap-1 text-[11px] ${ageTone(order.updated_at ?? order.created_at)}`}>
                    <Clock className="w-3 h-3" /> {timeAgo(order.updated_at ?? order.created_at)}
                </span>
            </div>
            <h3 className="mt-1.5 text-sm font-semibold text-content truncate">
                {order.customer_name || 'Walk-in customer'}
            </h3>
            <div className="mt-1 flex flex-wrap items-center gap-x-4 gap-y-1 text-xs text-content-muted">
                <span className="font-semibold tabular-nums text-content">{fmtMoney(order.total, currency)}</span>
                <span>{order.items?.length ?? 0} item(s)</span>
                {order.customer_phone && <span>{order.customer_phone}</span>}
            </div>
            {order.delivery_address && (
                <p className="mt-1 inline-flex items-start gap-1.5 text-xs text-content-muted">
                    <MapPin className="w-3.5 h-3.5 mt-px shrink-0" />
                    <span className="line-clamp-1">{order.delivery_address}</span>
                </p>
            )}
        </div>
    );
}

function CarrierChip({ shipment }) {
    const internal = shipment.carrier_type === 'internal';
    const Icon = internal ? User : Building2;

    return (
        <div className="text-right">
            <span className={[
                'inline-flex items-center gap-1.5 px-2.5 py-1 rounded-full text-xs font-semibold',
                internal
                    ? 'bg-indigo-500/15 text-indigo-700 dark:text-indigo-300'
                    : 'bg-blue-500/15 text-blue-700 dark:text-blue-300',
            ].join(' ')}>
                <Icon className="w-3.5 h-3.5" />
                {shipment.carrier_label}
            </span>

            {shipment.tracking_number && (
                <div className="mt-1 flex items-center justify-end gap-1.5 text-[11px] text-content-muted">
                    <span className="font-mono">{shipment.tracking_number}</span>
                    <button
                        onClick={() => navigator.clipboard?.writeText(shipment.tracking_number)}
                        aria-label="Copy tracking number"
                        className="hover:text-content transition"
                    >
                        <Copy className="w-3 h-3" />
                    </button>
                    {shipment.tracking_url && (
                        <a href={shipment.tracking_url} target="_blank" rel="noopener" className="hover:text-content transition">
                            <ExternalLink className="w-3 h-3" />
                        </a>
                    )}
                </div>
            )}

            {shipment.manifest_reference && (
                <div className="mt-0.5 inline-flex items-center gap-1 text-[11px] text-content-muted">
                    <FileText className="w-3 h-3" /> {shipment.manifest_reference}
                </div>
            )}
        </div>
    );
}

/* ------------------------------------------------------------------ */
/* Carrier assignment                                                  */
/* ------------------------------------------------------------------ */

function AssignCarrierDialog({ order, couriers, agents, onCancel, onConfirm }) {
    const [type, setType]         = useState('courier');
    const [name, setName]         = useState('');
    const [tracking, setTracking] = useState('');
    const [url, setUrl]           = useState('');
    const [agentId, setAgentId]   = useState('');
    const [manifest, setManifest] = useState('');

    if (! order) return null;

    const valid = type === 'courier' ? name.trim().length > 0 : agentId !== '';

    const submit = () => onConfirm({
        carrier_type:       type,
        carrier_name:       type === 'courier' ? name.trim() : null,
        tracking_number:    type === 'courier' ? tracking.trim() || null : null,
        tracking_url:       type === 'courier' ? url.trim() || null : null,
        agent_id:           type === 'internal' ? agentId : null,
        manifest_reference: manifest.trim() || null,
    });

    return (
        <div className="fixed inset-0 z-50 flex items-center justify-center p-4">
            <div onClick={onCancel} className="absolute inset-0 bg-black/50 backdrop-blur-sm" />
            <div role="dialog" aria-modal="true" className="relative w-full max-w-lg bg-surface border border-line rounded-2xl shadow-2xl p-5 max-h-[90vh] overflow-y-auto">
                <h3 className="text-base font-semibold text-content">Assign a carrier</h3>
                <p className="mt-0.5 text-sm text-content-muted">
                    {order.reference} · {order.customer_name || 'Walk-in customer'}
                </p>

                {/* Carrier type */}
                <div className="mt-4 grid grid-cols-2 gap-2">
                    {[
                        { value: 'courier',  label: 'Third-party courier', hint: 'Tracked externally', icon: Building2 },
                        { value: 'internal', label: 'Internal agent',      hint: 'One of our people',  icon: User },
                    ].map((opt) => {
                        const Icon = opt.icon;
                        const on = type === opt.value;
                        return (
                            <button
                                key={opt.value}
                                onClick={() => setType(opt.value)}
                                className={[
                                    'flex items-start gap-2.5 px-3 py-2.5 rounded-lg border text-left transition',
                                    on
                                        ? 'border-indigo-500 bg-indigo-500/10 text-indigo-700 dark:text-indigo-300'
                                        : 'border-line bg-surface-2 text-content-muted hover:text-content hover:bg-surface-3',
                                ].join(' ')}
                            >
                                <Icon className="w-4 h-4 mt-0.5 shrink-0" />
                                <span className="min-w-0">
                                    <span className="block text-sm font-medium">{opt.label}</span>
                                    <span className="block text-[11px] opacity-80">{opt.hint}</span>
                                </span>
                            </button>
                        );
                    })}
                </div>

                <div className="mt-4 space-y-3">
                    {type === 'courier' ? (
                        <>
                            <Field label="Courier">
                                <input
                                    autoFocus
                                    list="known-couriers"
                                    value={name}
                                    onChange={(e) => setName(e.target.value)}
                                    placeholder="e.g. Amana, CTM, DHL…"
                                    className={inputCls}
                                />
                                {/* Previously used couriers, still free text. */}
                                <datalist id="known-couriers">
                                    {couriers.map((c) => <option key={c} value={c} />)}
                                </datalist>
                            </Field>
                            <div className="grid grid-cols-1 sm:grid-cols-2 gap-3">
                                <Field label="Tracking number">
                                    <input value={tracking} onChange={(e) => setTracking(e.target.value)} placeholder="Optional" className={inputCls} />
                                </Field>
                                <Field label="Tracking URL">
                                    <input value={url} onChange={(e) => setUrl(e.target.value)} placeholder="https://…" className={inputCls} />
                                </Field>
                            </div>
                        </>
                    ) : (
                        <Field label="Delivery agent">
                            <select value={agentId} onChange={(e) => setAgentId(e.target.value)} className={inputCls}>
                                <option value="">Choose an agent…</option>
                                {agents.map((a) => (
                                    <option key={a.id} value={a.id}>
                                        {a.name}{a.assigned ? ` — ${a.assigned} in hand` : ''}
                                    </option>
                                ))}
                            </select>
                        </Field>
                    )}

                    <Field label="Manifest reference" hint="Groups a day's handover to one carrier for signing.">
                        <input value={manifest} onChange={(e) => setManifest(e.target.value)} placeholder="Optional — e.g. MAN-AMANA-20260724" className={inputCls} />
                    </Field>
                </div>

                <div className="mt-5 flex justify-end gap-2">
                    <button onClick={onCancel} className="px-3 py-2 text-sm font-medium rounded-lg bg-surface-2 border border-line text-content-muted hover:text-content transition">
                        Never mind
                    </button>
                    <button
                        disabled={! valid}
                        onClick={submit}
                        className="inline-flex items-center gap-1.5 px-3.5 py-2 text-sm font-semibold rounded-lg bg-indigo-600 text-white hover:bg-indigo-500 disabled:opacity-40 disabled:cursor-not-allowed transition"
                    >
                        <Send className="w-4 h-4" /> Dispatch
                    </button>
                </div>
            </div>
        </div>
    );
}

const inputCls = 'w-full px-3 py-2 text-sm rounded-lg bg-surface-2 border border-line text-content placeholder:text-content-muted focus:outline-none focus:ring-2 focus:ring-indigo-500/40 focus:border-indigo-500/50 transition';

function Field({ label, hint, children }) {
    return (
        <div>
            <label className="block text-xs font-medium text-content-muted mb-1.5">{label}</label>
            {children}
            {hint && <p className="mt-1 text-[11px] text-content-muted">{hint}</p>}
        </div>
    );
}
