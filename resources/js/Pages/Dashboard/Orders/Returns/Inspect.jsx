import { useState, useMemo } from 'react';
import { Link, router } from '@inertiajs/react';
import {
    CheckCircle2, XCircle, HelpCircle, Lock, Loader2, PackageCheck,
    AlertTriangle, ArrowLeft, User, Calendar,
} from 'lucide-react';
import SaasLayout from '@/Layouts/SaasLayout';
import StatusBadge from '@/Components/StatusBadge';

/* The inspection worksheet: one row per returned line, one verdict each.
   The verdict — not the order's status — decides where the units land. */

const CONDITIONS = [
    {
        value: 'resellable',
        label: 'Resellable',
        hint: 'Back into active stock',
        icon: CheckCircle2,
        active: 'border-success bg-success-soft text-success',
    },
    {
        value: 'damaged',
        label: 'Damaged',
        hint: 'Written off to damaged stock',
        icon: XCircle,
        active: 'border-danger bg-danger-soft text-danger',
    },
    {
        value: 'missing',
        label: 'Not received',
        hint: 'No stock movement',
        icon: HelpCircle,
        active: 'border-content-muted/40 bg-surface-soft text-content-muted',
    },
];

const DESTINATION = {
    resellable: 'Main warehouse',
    damaged:    'Damaged stock',
    missing:    '—',
};

export default function Inspect({ return: ret, summary }) {
    // Local verdicts, seeded from whatever has already been recorded.
    const [draft, setDraft] = useState(() => Object.fromEntries(
        ret.items.map((i) => [i.id, {
            condition: i.condition ?? null,
            quantity:  i.quantity_returned,
            notes:     i.notes ?? '',
        }]),
    ));
    const [busy, setBusy] = useState(false);

    const pending = useMemo(
        () => ret.items.filter((i) => ! draft[i.id]?.condition).length,
        [draft, ret.items],
    );

    const dirty = useMemo(
        () => ret.items.some((i) => ! i.locked && draft[i.id]?.condition && draft[i.id].condition !== i.condition),
        [draft, ret.items],
    );

    const closed = ret.status === 'closed';

    const set = (id, patch) => setDraft((d) => ({ ...d, [id]: { ...d[id], ...patch } }));

    const save = () => {
        const lines = ret.items
            .filter((i) => ! i.locked && draft[i.id]?.condition)
            .map((i) => ({
                item_id:   i.id,
                condition: draft[i.id].condition,
                quantity:  Number(draft[i.id].quantity) || 0,
                notes:     draft[i.id].notes || null,
            }));

        if (lines.length === 0) return;

        setBusy(true);
        router.post(`/dashboard/orders/returns/${ret.id}/disposition`, { lines }, {
            preserveScroll: true,
            onFinish: () => setBusy(false),
        });
    };

    const close = () => {
        setBusy(true);
        router.post(`/dashboard/orders/returns/${ret.id}/close`, {}, {
            preserveScroll: true,
            onFinish: () => setBusy(false),
        });
    };

    return (
        <SaasLayout pageHeader={{
            title: `Return ${ret.reference}`,
            subtitle: 'Record the condition of each line — stock moves when you save',
            breadcrumbs: [
                { label: 'Dashboard', href: '/dashboard' },
                { label: 'Orders', href: '/dashboard/orders/manage' },
                { label: 'Returns', href: '/dashboard/orders/returns' },
                { label: ret.reference },
            ],
            actions: (
                <Link
                    href="/dashboard/orders/returns"
                    className="inline-flex items-center gap-1.5 px-3 py-2 text-sm font-medium rounded-[var(--radius-button)] bg-surface border border-line text-content hover:bg-surface-3 transition"
                >
                    <ArrowLeft className="w-4 h-4" /> Back to queue
                </Link>
            ),
        }}>
            {/* Header card */}
            <section className="bg-surface-2 border border-line rounded-[var(--radius-card)] p-5 mb-5">
                <div className="flex flex-wrap items-start justify-between gap-4">
                    <div className="min-w-0">
                        <div className="flex items-center gap-2">
                            <StatusBadge type="return" status={ret.status} />
                            <span className="font-mono text-sm text-content">{ret.reference}</span>
                        </div>
                        <div className="mt-2 flex flex-wrap items-center gap-x-5 gap-y-1 text-sm text-content-muted">
                            {ret.order_reference && (
                                <span>
                                    Order{' '}
                                    <Link
                                        href="/dashboard/orders/manage"
                                        className="font-mono text-content hover:text-primary transition"
                                    >
                                        {ret.order_reference}
                                    </Link>
                                </span>
                            )}
                            {ret.customer_name && (
                                <span className="inline-flex items-center gap-1.5"><User className="w-3.5 h-3.5" />{ret.customer_name}</span>
                            )}
                            {ret.flagged_by && (
                                <span className="inline-flex items-center gap-1.5">
                                    <Calendar className="w-3.5 h-3.5" />Flagged by {ret.flagged_by}
                                </span>
                            )}
                        </div>
                        {ret.notes && <p className="mt-2 text-sm text-content-muted italic">“{ret.notes}”</p>}
                    </div>

                    <div className="flex gap-3">
                        <Tally label="Restocked" value={summary.restocked} tone="success" />
                        <Tally label="Damaged"   value={summary.damaged}   tone="danger" />
                        <Tally label="Missing"   value={summary.missing}   tone="slate" />
                    </div>
                </div>
            </section>

            {/* Worksheet */}
            <div className="space-y-3">
                {ret.items.map((item) => {
                    const d = draft[item.id] ?? {};
                    return (
                        <article
                            key={item.id}
                            className={[
                                'bg-surface border rounded-[var(--radius-card)] p-4 transition',
                                item.locked ? 'border-line opacity-75' : 'border-line',
                            ].join(' ')}
                        >
                            <div className="flex flex-wrap items-start justify-between gap-3">
                                <div className="min-w-0">
                                    <h3 className="text-sm font-semibold text-content truncate">{item.product_name}</h3>
                                    <div className="mt-0.5 flex items-center gap-3 text-xs text-content-muted">
                                        {item.product_sku && <span className="font-mono">{item.product_sku}</span>}
                                        <span>{item.quantity_ordered} ordered</span>
                                        {! item.movable && (
                                            <span className="inline-flex items-center gap-1 text-warning">
                                                <AlertTriangle className="w-3 h-3" /> No linked product — no stock will move
                                            </span>
                                        )}
                                    </div>
                                </div>

                                {item.locked ? (
                                    <span className="inline-flex items-center gap-1.5 px-2.5 py-1 rounded-[var(--radius-button)] bg-surface-2 border border-line text-xs text-content-muted">
                                        <Lock className="w-3.5 h-3.5" /> Stock already moved
                                    </span>
                                ) : (
                                    <div className="flex items-center gap-2">
                                        <label className="text-xs text-content-muted">Received</label>
                                        <input
                                            type="number"
                                            min={0}
                                            max={item.quantity_ordered}
                                            value={d.quantity ?? 0}
                                            onChange={(e) => set(item.id, { quantity: e.target.value })}
                                            className="w-20 px-2 py-1.5 text-sm text-right tabular-nums rounded-[var(--radius-button)] bg-surface-2 border border-line text-content focus:outline-none focus:ring-2 focus:ring-primary/40"
                                        />
                                    </div>
                                )}
                            </div>

                            {/* Condition picker */}
                            <div className="mt-3 grid grid-cols-1 sm:grid-cols-3 gap-2">
                                {CONDITIONS.map((c) => {
                                    const Icon = c.icon;
                                    const isActive = d.condition === c.value;
                                    return (
                                        <button
                                            key={c.value}
                                            disabled={item.locked}
                                            onClick={() => set(item.id, { condition: c.value })}
                                            className={[
                                                'flex items-start gap-2.5 px-3 py-2.5 rounded-[var(--radius-button)] border text-left transition disabled:cursor-not-allowed',
                                                isActive ? c.active : 'border-line bg-surface-2 text-content-muted hover:text-content hover:bg-surface-3',
                                            ].join(' ')}
                                        >
                                            <Icon className="w-4 h-4 mt-0.5 shrink-0" />
                                            <span className="min-w-0">
                                                <span className="block text-sm font-medium">{c.label}</span>
                                                <span className="block text-[11px] opacity-80">{c.hint}</span>
                                            </span>
                                        </button>
                                    );
                                })}
                            </div>

                            {d.condition && (
                                <div className="mt-3 flex flex-wrap items-center gap-3">
                                    <span className="inline-flex items-center gap-1.5 text-xs text-content-muted">
                                        <PackageCheck className="w-3.5 h-3.5" />
                                        Destination: <strong className="text-content">{DESTINATION[d.condition]}</strong>
                                    </span>
                                    {! item.locked && (
                                        <input
                                            value={d.notes ?? ''}
                                            onChange={(e) => set(item.id, { notes: e.target.value })}
                                            placeholder="Inspection note (optional)…"
                                            className="flex-1 min-w-48 px-3 py-1.5 text-sm rounded-[var(--radius-button)] bg-surface-2 border border-line text-content placeholder:text-content-muted focus:outline-none focus:ring-2 focus:ring-primary/40"
                                        />
                                    )}
                                </div>
                            )}
                        </article>
                    );
                })}
            </div>

            {/* Footer actions */}
            <div className="sticky bottom-0 mt-5 -mx-4 px-4 py-4 bg-surface/80 backdrop-blur border-t border-line flex flex-wrap items-center justify-between gap-3">
                <p className="text-sm text-content-muted">
                    {closed
                        ? 'This return is closed.'
                        : `${ret.line_count - pending} of ${ret.line_count} lines inspected`}
                </p>
                <div className="flex items-center gap-2">
                    <button
                        disabled={busy || closed || ! dirty}
                        onClick={save}
                        className="inline-flex items-center gap-1.5 px-4 py-2 text-sm font-semibold rounded-[var(--radius-button)] bg-surface-2 border border-line text-content hover:bg-surface-3 disabled:opacity-40 disabled:cursor-not-allowed transition"
                    >
                        {busy ? <Loader2 className="w-4 h-4 animate-spin" /> : <PackageCheck className="w-4 h-4" />}
                        Save inspection
                    </button>
                    {/* Disabled, not hidden — the server enforces the same rule. */}
                    <button
                        disabled={busy || closed || pending > 0 || dirty}
                        onClick={close}
                        title={pending > 0 ? 'Every line needs a condition first' : dirty ? 'Save your changes first' : undefined}
                        className="inline-flex items-center gap-1.5 px-4 py-2 text-sm font-semibold rounded-[var(--radius-button)] bg-primary text-primary-contrast hover:bg-primary-strong disabled:opacity-40 disabled:cursor-not-allowed transition"
                    >
                        {busy ? <Loader2 className="w-4 h-4 animate-spin" /> : <CheckCircle2 className="w-4 h-4" />}
                        Close return
                    </button>
                </div>
            </div>
        </SaasLayout>
    );
}

function Tally({ label, value, tone }) {
    const tones = {
        success: 'text-success',
        danger:  'text-danger',
        slate:   'text-content-muted',
    };
    return (
        <div className="text-center">
            <div className={`text-xl font-bold tabular-nums ${tones[tone]}`}>{value}</div>
            <div className="text-[11px] uppercase tracking-wider text-content-muted">{label}</div>
        </div>
    );
}
