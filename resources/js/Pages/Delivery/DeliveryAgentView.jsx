import { useState, useEffect } from 'react';
import { router } from '@inertiajs/react';
import {
    Phone, MapPin, Navigation, CheckCircle2, XCircle, Loader2, Package,
    Monitor, Globe, Banknote, Wallet, PackageCheck, Clock,
} from 'lucide-react';
import DeliveryAgentLayout from '@/Layouts/DeliveryAgentLayout';

/**
 * Delivery agent view — a driver's own queue on a phone.
 *
 * Standalone (DeliveryAgentLayout, not the manager shell): no metrics, no
 * sidebar. Two tabs — the live queue to work, and a read-only history of what
 * was delivered or failed. Everything posts back and re-reads fresh props, so a
 * card leaves the queue the instant its shipment closes.
 */

const FAILURE_REASONS = [
    { value: 'customer_unreachable', label: 'Customer unreachable' },
    { value: 'refused',              label: 'Customer refused' },
    { value: 'wrong_address',        label: 'Wrong / bad address' },
    { value: 'damaged_in_transit',   label: 'Parcel damaged' },
    { value: 'other',                label: 'Other…' },
];

const REASON_LABEL = Object.fromEntries(FAILURE_REASONS.map((r) => [r.value, r.label]));

export default function DeliveryAgentView({ store, agent, deliveries = [], history = [], reconciliation }) {
    const currency = store?.currency ?? 'MAD';

    const [tab, setTab]           = useState('pending');
    const [busyId, setBusyId]     = useState(null);
    const [delivering, setDelivering] = useState(null);
    const [failing, setFailing]       = useState(null);

    const post = (id, url, data, onDone) => {
        setBusyId(id);
        router.post(url, data, {
            preserveScroll: true,
            preserveState: true,
            onFinish: () => { setBusyId(null); onDone?.(); },
        });
    };

    return (
        <DeliveryAgentLayout store={store} agent={agent} pending={deliveries.length}>
            {reconciliation && <Reconciliation data={reconciliation} currency={currency} />}

            {/* Tabs */}
            <div className="mt-4 grid grid-cols-2 gap-1 p-1 rounded-xl bg-surface border border-line">
                <TabButton active={tab === 'pending'} onClick={() => setTab('pending')} label="Pending" count={deliveries.length} />
                <TabButton active={tab === 'history'} onClick={() => setTab('history')} label="History" count={history.length} />
            </div>

            {tab === 'pending' ? (
                deliveries.length === 0 ? (
                    <EmptyState
                        icon={PackageCheck}
                        title="All caught up"
                        hint="No parcels in your queue right now."
                    />
                ) : (
                    <ul className="mt-3 space-y-3">
                        {deliveries.map((d) => (
                            <DeliveryCard
                                key={d.id}
                                parcel={d}
                                currency={currency}
                                busy={busyId === d.id}
                                onDeliver={() => setDelivering(d)}
                                onFail={() => setFailing(d)}
                            />
                        ))}
                    </ul>
                )
            ) : (
                history.length === 0 ? (
                    <EmptyState icon={Clock} title="Nothing yet" hint="Delivered and failed drops will show up here." />
                ) : (
                    <ul className="mt-3 space-y-3">
                        {history.map((h) => <HistoryCard key={h.id} parcel={h} currency={currency} />)}
                    </ul>
                )
            )}

            <DeliverSheet
                parcel={delivering}
                currency={currency}
                busy={delivering && busyId === delivering.id}
                onClose={() => setDelivering(null)}
                onConfirm={(cod) => post(
                    delivering.id,
                    `/dashboard/my-deliveries/${delivering.id}/delivered`,
                    cod === null ? {} : { cod_collected: cod },
                    () => setDelivering(null),
                )}
            />

            <FailSheet
                parcel={failing}
                busy={failing && busyId === failing.id}
                onClose={() => setFailing(null)}
                onConfirm={(reason) => post(
                    failing.id,
                    `/dashboard/my-deliveries/${failing.id}/failed`,
                    { reason },
                    () => setFailing(null),
                )}
            />
        </DeliveryAgentLayout>
    );
}

/* ------------------------------------------------------------------ */

function TabButton({ active, onClick, label, count }) {
    return (
        <button
            onClick={onClick}
            className={[
                'inline-flex items-center justify-center gap-1.5 py-2 text-sm font-semibold rounded-lg transition',
                active ? 'bg-indigo-600 text-white shadow-sm' : 'text-content-muted hover:text-content',
            ].join(' ')}
        >
            {label}
            <span className={[
                'min-w-5 px-1.5 rounded-full text-[11px] tabular-nums',
                active ? 'bg-white/20' : 'bg-surface-2 border border-line',
            ].join(' ')}>{count}</span>
        </button>
    );
}

function Reconciliation({ data, currency }) {
    return (
        <section className="bg-gradient-to-br from-indigo-600 to-indigo-500 text-white rounded-2xl p-5 shadow-sm">
            <div className="flex items-center gap-2 text-indigo-100 text-xs font-medium">
                <Wallet className="w-4 h-4" /> Cash reconciliation · today
            </div>
            <div className="mt-3 grid grid-cols-2 gap-4">
                <div>
                    <div className="text-2xl font-bold tabular-nums">{money(data.collected_today, currency)}</div>
                    <div className="text-[11px] text-indigo-100">Collected to hand in</div>
                </div>
                <div>
                    <div className="text-2xl font-bold tabular-nums">{money(data.outstanding, currency)}</div>
                    <div className="text-[11px] text-indigo-100">Still to collect</div>
                </div>
            </div>
            <div className="mt-3 pt-3 border-t border-white/20 flex items-center gap-4 text-xs text-indigo-100">
                <span className="inline-flex items-center gap-1.5"><Package className="w-3.5 h-3.5" /> {data.in_queue} in queue</span>
                <span className="inline-flex items-center gap-1.5"><CheckCircle2 className="w-3.5 h-3.5" /> {data.delivered_today} delivered</span>
            </div>
        </section>
    );
}

function SourceBadge({ source }) {
    const pos = source === 'pos';
    return (
        <span className={[
            'inline-flex items-center gap-1 px-2 py-0.5 rounded-full text-[11px] font-semibold',
            pos ? 'bg-indigo-500/15 text-indigo-700 dark:text-indigo-300'
                : 'bg-blue-500/15 text-blue-700 dark:text-blue-300',
        ].join(' ')}>
            {pos ? <Monitor className="w-3 h-3" /> : <Globe className="w-3 h-3" />}
            {pos ? 'POS' : 'Online'}
        </span>
    );
}

function DeliveryCard({ parcel, currency, busy, onDeliver, onFail }) {
    const cod = Number(parcel.cod_amount) || 0;
    const mapsUrl = parcel.address
        ? `https://www.google.com/maps/search/?api=1&query=${encodeURIComponent(parcel.address)}`
        : null;

    return (
        <li className="bg-surface border border-line rounded-2xl overflow-hidden">
            <div className="p-4">
                <div className="flex items-center justify-between gap-2">
                    <SourceBadge source={parcel.source} />
                    <span className="font-mono text-[11px] text-content-muted">{parcel.order_reference}</span>
                </div>

                <h3 className="mt-2 text-lg font-semibold text-content leading-tight">
                    {parcel.customer_name || 'Customer'}
                </h3>

                {parcel.customer_phone && (
                    <p className="mt-1 flex items-center gap-1.5 text-sm text-content-muted">
                        <Phone className="w-4 h-4 shrink-0" /> {parcel.customer_phone}
                    </p>
                )}
                {parcel.address && (
                    <p className="mt-1 flex items-start gap-1.5 text-sm text-content-muted">
                        <MapPin className="w-4 h-4 mt-0.5 shrink-0" /> <span>{parcel.address}</span>
                    </p>
                )}

                {/* Total + COD indicator */}
                <div className="mt-3 flex items-center justify-between gap-3">
                    <div>
                        <div className="text-[11px] text-content-muted">Order total</div>
                        <div className="text-lg font-bold tabular-nums text-content">{money(parcel.total, currency)}</div>
                    </div>
                    {cod > 0 ? (
                        <span className="inline-flex items-center gap-1.5 px-2.5 py-1.5 rounded-lg bg-amber-500/15 text-amber-700 dark:text-amber-300 text-sm font-semibold">
                            <Banknote className="w-4 h-4" /> Collect {money(cod, currency)}
                        </span>
                    ) : (
                        <span className="inline-flex items-center gap-1.5 px-2.5 py-1.5 rounded-lg bg-emerald-500/15 text-emerald-700 dark:text-emerald-300 text-sm font-semibold">
                            <CheckCircle2 className="w-4 h-4" /> Prepaid
                        </span>
                    )}
                </div>
            </div>

            {/* Utility shortcuts */}
            <div className="grid grid-cols-2 border-t border-line divide-x divide-line">
                <a
                    href={parcel.customer_phone ? `tel:${parcel.customer_phone}` : undefined}
                    aria-disabled={! parcel.customer_phone}
                    className={[
                        'flex items-center justify-center gap-2 py-3 text-sm font-semibold transition',
                        parcel.customer_phone ? 'text-content hover:bg-surface-3' : 'text-content-muted/40 pointer-events-none',
                    ].join(' ')}
                >
                    <Phone className="w-4 h-4" /> Call
                </a>
                <a
                    href={mapsUrl ?? undefined}
                    target="_blank"
                    rel="noopener"
                    aria-disabled={! mapsUrl}
                    className={[
                        'flex items-center justify-center gap-2 py-3 text-sm font-semibold transition',
                        mapsUrl ? 'text-content hover:bg-surface-3' : 'text-content-muted/40 pointer-events-none',
                    ].join(' ')}
                >
                    <Navigation className="w-4 h-4" /> Navigate
                </a>
            </div>

            {/* Outcome */}
            <div className="grid grid-cols-2 border-t border-line divide-x divide-line">
                <button
                    disabled={busy}
                    onClick={onFail}
                    className="flex items-center justify-center gap-2 py-3.5 text-sm font-semibold text-red-600 dark:text-red-400 hover:bg-red-500/10 disabled:opacity-40 transition"
                >
                    <XCircle className="w-4 h-4" /> Failed
                </button>
                <button
                    disabled={busy}
                    onClick={onDeliver}
                    className="flex items-center justify-center gap-2 py-3.5 text-sm font-semibold text-white bg-emerald-600 hover:bg-emerald-500 disabled:opacity-40 transition"
                >
                    {busy ? <Loader2 className="w-4 h-4 animate-spin" /> : <CheckCircle2 className="w-4 h-4" />}
                    Delivered
                </button>
            </div>
        </li>
    );
}

function HistoryCard({ parcel, currency }) {
    const delivered = parcel.status === 'delivered';

    return (
        <li className="bg-surface border border-line rounded-2xl p-4 opacity-95">
            <div className="flex items-center justify-between gap-2">
                <span className={[
                    'inline-flex items-center gap-1 px-2 py-0.5 rounded-full text-[11px] font-semibold',
                    delivered ? 'bg-emerald-500/15 text-emerald-700 dark:text-emerald-300'
                              : 'bg-red-500/15 text-red-700 dark:text-red-300',
                ].join(' ')}>
                    {delivered ? <CheckCircle2 className="w-3 h-3" /> : <XCircle className="w-3 h-3" />}
                    {delivered ? 'Delivered' : 'Failed'}
                </span>
                <span className="font-mono text-[11px] text-content-muted">{parcel.order_reference}</span>
            </div>

            <div className="mt-2 flex items-center justify-between gap-3">
                <div className="min-w-0">
                    <div className="text-sm font-semibold text-content truncate">{parcel.customer_name}</div>
                    <div className="text-xs text-content-muted">{timeAgo(parcel.closed_at)}</div>
                </div>
                <div className="text-right shrink-0">
                    {delivered ? (
                        Number(parcel.cod_collected) > 0 ? (
                            <span className="text-sm font-semibold tabular-nums text-emerald-600 dark:text-emerald-400">
                                +{money(parcel.cod_collected, currency)}
                            </span>
                        ) : (
                            <span className="text-xs text-content-muted">Prepaid</span>
                        )
                    ) : (
                        <span className="text-xs text-red-600 dark:text-red-400">
                            {REASON_LABEL[parcel.failure_reason] ?? parcel.failure_reason ?? 'Failed'}
                        </span>
                    )}
                </div>
            </div>
        </li>
    );
}

function EmptyState({ icon: Icon, title, hint }) {
    return (
        <div className="mt-3 bg-surface border border-line rounded-2xl py-16 text-center">
            <Icon className="w-11 h-11 mx-auto text-content-muted" strokeWidth={1.5} />
            <h3 className="mt-3 text-base font-semibold text-content">{title}</h3>
            <p className="mt-1 text-sm text-content-muted">{hint}</p>
        </div>
    );
}

/* ------------------------------------------------------------------ */
/* Bottom sheets                                                       */
/* ------------------------------------------------------------------ */

function Sheet({ open, onClose, children }) {
    useEffect(() => {
        if (! open) return;
        const onKey = (e) => e.key === 'Escape' && onClose();
        window.addEventListener('keydown', onKey);
        return () => window.removeEventListener('keydown', onKey);
    }, [open, onClose]);

    if (! open) return null;

    return (
        <div className="fixed inset-0 z-50 flex items-end sm:items-center justify-center">
            <div onClick={onClose} className="absolute inset-0 bg-black/50 backdrop-blur-sm" />
            <div className="relative w-full sm:max-w-md bg-surface border-t sm:border border-line sm:rounded-2xl rounded-t-2xl shadow-2xl p-5 pb-8 sm:pb-5">
                <div className="sm:hidden mx-auto mb-3 h-1 w-10 rounded-full bg-line" />
                {children}
            </div>
        </div>
    );
}

function DeliverSheet({ parcel, currency, busy, onClose, onConfirm }) {
    const cod = parcel ? Number(parcel.cod_amount) || 0 : 0;
    const [collected, setCollected] = useState('');

    useEffect(() => { if (parcel) setCollected(cod > 0 ? String(cod) : ''); }, [parcel, cod]);

    if (! parcel) return null;

    const short = cod > 0 && Number(collected) < cod;

    return (
        <Sheet open onClose={onClose}>
            <div className="flex items-center gap-3">
                <span className="inline-flex items-center justify-center w-10 h-10 rounded-xl bg-emerald-500/15 text-emerald-600 dark:text-emerald-400">
                    <PackageCheck className="w-5 h-5" />
                </span>
                <div>
                    <h3 className="text-base font-semibold text-content">Confirm delivery</h3>
                    <p className="text-sm text-content-muted">{parcel.customer_name} · {parcel.order_reference}</p>
                </div>
            </div>

            {cod > 0 ? (
                <div className="mt-4">
                    <label className="block text-xs font-medium text-content-muted mb-1.5">
                        Cash collected (expected {money(cod, currency)})
                    </label>
                    <div className="relative">
                        <Banknote className="absolute left-3 top-1/2 -translate-y-1/2 w-4 h-4 text-content-muted" />
                        <input
                            type="number"
                            inputMode="decimal"
                            min={0}
                            step="0.01"
                            value={collected}
                            onChange={(e) => setCollected(e.target.value)}
                            className="w-full pl-9 pr-14 py-3 text-lg font-semibold tabular-nums rounded-xl bg-surface-2 border border-line text-content focus:outline-none focus:ring-2 focus:ring-emerald-500/40"
                        />
                        <span className="absolute right-3 top-1/2 -translate-y-1/2 text-sm text-content-muted">{currency}</span>
                    </div>
                    {short && (
                        <p className="mt-1.5 text-[11px] text-amber-600 dark:text-amber-400">
                            Short by {money(cod - Number(collected), currency)} — recorded as a partial collection.
                        </p>
                    )}
                </div>
            ) : (
                <p className="mt-4 text-sm text-content-muted">This order is prepaid — no cash to collect.</p>
            )}

            <div className="mt-5 flex gap-2">
                <button onClick={onClose} className="flex-1 py-3 text-sm font-medium rounded-xl bg-surface-2 border border-line text-content-muted hover:text-content transition">
                    Cancel
                </button>
                <button
                    disabled={busy}
                    onClick={() => onConfirm(cod > 0 ? Number(collected || 0) : null)}
                    className="flex-1 inline-flex items-center justify-center gap-1.5 py-3 text-sm font-semibold rounded-xl bg-emerald-600 text-white hover:bg-emerald-500 disabled:opacity-50 transition"
                >
                    {busy ? <Loader2 className="w-4 h-4 animate-spin" /> : <CheckCircle2 className="w-4 h-4" />}
                    Confirm delivery
                </button>
            </div>
        </Sheet>
    );
}

function FailSheet({ parcel, busy, onClose, onConfirm }) {
    const [reason, setReason] = useState('customer_unreachable');
    const [note, setNote]     = useState('');

    useEffect(() => { if (parcel) { setReason('customer_unreachable'); setNote(''); } }, [parcel]);

    if (! parcel) return null;

    const value = reason === 'other' ? note.trim() : reason;

    return (
        <Sheet open onClose={onClose}>
            <div className="flex items-center gap-3">
                <span className="inline-flex items-center justify-center w-10 h-10 rounded-xl bg-red-500/15 text-red-600 dark:text-red-400">
                    <XCircle className="w-5 h-5" />
                </span>
                <div>
                    <h3 className="text-base font-semibold text-content">Delivery failed</h3>
                    <p className="text-sm text-content-muted">{parcel.customer_name} · {parcel.order_reference}</p>
                </div>
            </div>

            <p className="mt-3 text-xs text-content-muted">
                The order moves to the returns queue for inspection — no stock changes until it is checked back in.
            </p>

            <div className="mt-4 space-y-2">
                {FAILURE_REASONS.map((r) => (
                    <button
                        key={r.value}
                        onClick={() => setReason(r.value)}
                        className={[
                            'w-full flex items-center gap-2.5 px-3 py-3 rounded-xl border text-left text-sm font-medium transition',
                            reason === r.value
                                ? 'border-red-500 bg-red-500/10 text-red-700 dark:text-red-300'
                                : 'border-line bg-surface-2 text-content-muted hover:text-content',
                        ].join(' ')}
                    >
                        <span className={[
                            'w-4 h-4 rounded-full border-2 shrink-0',
                            reason === r.value ? 'border-red-500 bg-red-500' : 'border-line',
                        ].join(' ')} />
                        {r.label}
                    </button>
                ))}

                {reason === 'other' && (
                    <textarea
                        autoFocus
                        rows={2}
                        value={note}
                        onChange={(e) => setNote(e.target.value)}
                        placeholder="What happened?"
                        className="w-full px-3 py-2 text-sm rounded-xl bg-surface-2 border border-line text-content placeholder:text-content-muted focus:outline-none focus:ring-2 focus:ring-red-500/40"
                    />
                )}
            </div>

            <div className="mt-5 flex gap-2">
                <button onClick={onClose} className="flex-1 py-3 text-sm font-medium rounded-xl bg-surface-2 border border-line text-content-muted hover:text-content transition">
                    Cancel
                </button>
                <button
                    disabled={busy || ! value}
                    onClick={() => onConfirm(value)}
                    className="flex-1 inline-flex items-center justify-center gap-1.5 py-3 text-sm font-semibold rounded-xl bg-red-600 text-white hover:bg-red-500 disabled:opacity-40 disabled:cursor-not-allowed transition"
                >
                    {busy ? <Loader2 className="w-4 h-4 animate-spin" /> : <XCircle className="w-4 h-4" />}
                    Report failure
                </button>
            </div>
        </Sheet>
    );
}

function money(value, currency) {
    const n = Number(value) || 0;
    return `${n.toLocaleString(undefined, { minimumFractionDigits: 2, maximumFractionDigits: 2 })} ${currency}`;
}

function timeAgo(iso) {
    if (! iso) return '';
    const mins = Math.floor((Date.now() - new Date(iso).getTime()) / 60000);
    if (mins < 1) return 'just now';
    if (mins < 60) return `${mins}m ago`;
    const hrs = Math.floor(mins / 60);
    if (hrs < 24) return `${hrs}h ago`;
    return `${Math.floor(hrs / 24)}d ago`;
}
