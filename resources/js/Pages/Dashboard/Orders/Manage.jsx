import { useState, useMemo, useEffect } from 'react';
import { Link, router, usePage } from '@inertiajs/react';
import axios from 'axios';
import {
    ShoppingCart, Monitor, Globe, Truck, LayoutGrid, Table2, X,
    Eye, Printer, User, Phone, Mail, CreditCard, Clock, Package, Loader2,
    Undo2, AlertTriangle, MapPin, UserCircle2,
} from 'lucide-react';
import SaasLayout from '@/Layouts/SaasLayout';
import StatusBadge from '@/Components/StatusBadge';
import SearchFilterBar from '@/Components/SearchFilterBar';

/* Fulfillment workflow — mirrors App\Enums\FulfillmentStatus, including its
   phase(). Real backend statuses only — nothing here is invented. */
const COLUMNS = [
    { value: 'pending',            label: 'Pending confirmation', color: 'amber',   phase: 'confirmation' },
    { value: 'confirmed',          label: 'Confirmed',            color: 'blue',    phase: 'fulfillment'  },
    { value: 'in_progress',        label: 'Processing',           color: 'indigo',  phase: 'fulfillment'  },
    { value: 'ready_for_delivery', label: 'Ready for delivery',   color: 'cyan',    phase: 'delivery'     },
    { value: 'delivered',          label: 'Delivered',            color: 'teal',    phase: 'delivery'     },
    { value: 'completed',          label: 'Completed',            color: 'emerald', phase: 'closed'       },
    { value: 'cancelled',          label: 'Cancelled',            color: 'slate',   phase: 'closed'       },
    { value: 'returned',           label: 'Returned',             color: 'red',     phase: 'returns'      },
    { value: 'under_inspection',   label: 'Under inspection',     color: 'amber',   phase: 'returns'      },
    { value: 'return_completed',   label: 'Return closed',        color: 'slate',   phase: 'returns'      },
];

// The brief's own curated example (Pending confirmation / Confirmed /
// Processing / Ready for delivery / Delivered / Returned) — the default,
// decluttered board. Completed/Cancelled/Under inspection/Return closed are
// still fully real statuses, just reached via the status filter instead of
// a 7th–10th always-visible lane.
const PRIMARY_FLOW = ['pending', 'confirmed', 'in_progress', 'ready_for_delivery', 'delivered', 'returned'];

const DOT = {
    amber: 'bg-amber-400', blue: 'bg-blue-400', cyan: 'bg-cyan-400', indigo: 'bg-indigo-400',
    teal: 'bg-teal-400', emerald: 'bg-emerald-400', slate: 'bg-slate-400', red: 'bg-red-400',
};

/* Source is the sales channel only — POS vs online. Delivery is a fulfillment
   stage, not a channel: it lives in the status columns, never here. */
const SOURCES = [
    { value: 'pos',    label: 'Direct POS'  },
    { value: 'online', label: 'Online store' },
];

const SOURCE_TONE = {
    pos:    'bg-indigo-500/15 text-indigo-700 dark:text-indigo-300',
    online: 'bg-blue-500/15 text-blue-700 dark:text-blue-300',
};

export default function Manage({ store, orders = [], cities = [] }) {
    const currency = store?.currency ?? 'MAD';

    // Opening the orders board is what "seen" means for the new-order badge
    // — marks every unseen new_order notification for this user, once.
    useEffect(() => {
        axios.post('/dashboard/notifications/mark-seen', { context: 'orders_index' }).catch(() => {});
    }, []);

    const permissions = usePage().props.auth?.permissions ?? [];
    const can = (perm) => ! perm || permissions.includes('*') || permissions.includes(perm);

    const [view, setView]           = useState('board');      // 'board' | 'table'
    const [status, setStatus]       = useState('all');        // 'all' | a real COLUMNS value
    const [source, setSource]       = useState('');
    const [assigned, setAssigned]   = useState('');
    const [search, setSearch]       = useState('');
    const [dateFrom, setDateFrom]   = useState('');
    const [dateTo, setDateTo]       = useState('');
    const [selectedKey, setKey]     = useState(null);         // `${type}:${id}`
    const [busy, setBusy]           = useState(null);         // key being updated
    const [reasonFor, setReason]    = useState(null);         // { order, transition }
    const [confirmFor, setConfirm]  = useState(null);         // confirmation data correction

    // One real assignee list, derived from the orders actually loaded — never
    // an invented roster.
    const assignees = useMemo(
        () => [...new Set(orders.map((o) => o.assignee_name).filter(Boolean))].sort(),
        [orders],
    );

    const counts = useMemo(() => {
        const bySource = { pos: 0, online: 0 };
        const byStatus = Object.fromEntries(COLUMNS.map((c) => [c.value, 0]));
        for (const o of orders) {
            byStatus[o.status] = (byStatus[o.status] ?? 0) + 1;
            bySource[o.source] = (bySource[o.source] ?? 0) + 1;
        }
        return { bySource, byStatus };
    }, [orders]);

    const filtered = useMemo(() => {
        const q = search.trim().toLowerCase();
        return orders.filter((o) => {
            if (status !== 'all' && o.status !== status) return false;
            if (source && o.source !== source) return false;
            if (assigned === '__unassigned__' && o.assignee_name) return false;
            if (assigned && assigned !== '__unassigned__' && o.assignee_name !== assigned) return false;
            if (dateFrom && o.created_at < dateFrom) return false;
            if (dateTo && o.created_at.slice(0, 10) > dateTo) return false;
            if (q) {
                const hit = [o.reference, o.customer_name, o.customer_email, o.customer_phone]
                    .filter(Boolean)
                    .some((v) => String(v).toLowerCase().includes(q));
                if (! hit) return false;
            }
            return true;
        });
    }, [orders, status, source, assigned, search, dateFrom, dateTo]);

    // Board columns: the curated primary flow by default, or just the one
    // selected status when the filter narrows to it — never a 7-10 lane wall.
    const boardColumns = useMemo(() => {
        if (status !== 'all') return COLUMNS.filter((c) => c.value === status);
        return COLUMNS.filter((c) => PRIMARY_FLOW.includes(c.value));
    }, [status]);

    // Keep the drawer bound to fresh data after a status update re-renders props.
    const selected = useMemo(
        () => (selectedKey ? filtered.find((o) => `${o.type}:${o.id}` === selectedKey)
            ?? orders.find((o) => `${o.type}:${o.id}` === selectedKey) ?? null : null),
        [selectedKey, filtered, orders],
    );

    const post = (order, transitionStatus, reason, extra = {}) => {
        const key = `${order.type}:${order.id}`;
        setBusy(key);
        router.post(`/dashboard/orders/${order.type}/${order.id}/status`, { status: transitionStatus, reason, ...extra }, {
            preserveScroll: true,
            preserveState: true,   // keep filters + drawer open; props still refresh
            onFinish: () => setBusy(null),
        });
    };

    /* Cancelling and flagging a return destroy or divert value, so the server
       rejects them without a reason. Collect it first rather than letting the
       request bounce. */
    const updateStatus = (order, transition) => {
        if (transition.value === 'confirmed') {
            setConfirm({ order, transition });
            return;
        }
        if (transition.requires_reason) {
            setReason({ order, transition });
            return;
        }
        post(order, transition.value);
    };

    const statusOptions = COLUMNS.map((c) => ({ value: c.value, label: c.label }));

    return (
        <SaasLayout pageHeader={{
            title: 'Order management',
            subtitle: 'Every channel — POS, online and delivery — in one workflow',
            breadcrumbs: [{ label: 'Dashboard', href: '/dashboard' }, { label: 'Orders' }],
            actions: (
                <div className="flex items-center gap-2">
                    <ViewToggle view={view} onChange={setView} />
                    {can('orders.inspect') && (
                        <Link
                            href="/dashboard/orders/returns"
                            className="inline-flex items-center gap-1.5 px-3 py-2 text-sm font-semibold rounded-[var(--radius-button)] bg-surface border border-line text-content hover:bg-surface-3 transition"
                        >
                            <Undo2 className="w-4 h-4" /> Returns
                        </Link>
                    )}
                    <Link
                        href="/pos"
                        className="inline-flex items-center gap-1.5 px-3 py-2 text-sm font-semibold rounded-[var(--radius-button)] bg-primary text-primary-contrast hover:bg-primary-strong transition"
                    >
                        <Monitor className="w-4 h-4" /> Open POS
                    </Link>
                </div>
            ),
        }}>
            {/* Compact summary strip — one slim row, not a card grid. Clicking a
                status narrows the filter below (click again to clear). */}
            <div className="mb-5 flex flex-wrap items-center gap-2 rounded-[var(--radius-card)] border border-line bg-surface-2 px-3 py-2.5">
                {COLUMNS.filter((c) => PRIMARY_FLOW.includes(c.value)).map((c) => {
                    const active = status === c.value;
                    return (
                        <button
                            key={c.value}
                            type="button"
                            onClick={() => setStatus(active ? 'all' : c.value)}
                            className={`inline-flex items-center gap-1.5 rounded-full px-2.5 py-1.5 text-xs font-medium transition ${
                                active ? 'bg-primary-soft text-primary' : 'text-content-muted hover:bg-surface-3 hover:text-content'
                            }`}
                        >
                            <span className={`h-1.5 w-1.5 rounded-full ${DOT[c.color]}`} />
                            {c.label}
                            <span className="tabular-nums font-semibold">{counts.byStatus[c.value] ?? 0}</span>
                        </button>
                    );
                })}
            </div>

            {/* One filter bar — search, source, status, assigned, date range. */}
            <div className="mb-5">
                <SearchFilterBar
                    placeholder="Search reference, customer…"
                    value={search}
                    onSearch={setSearch}
                    activeFilters={{ source, status: status === 'all' ? '' : status, assigned }}
                    onFilterChange={(key, val) => {
                        if (key === 'source') setSource(val);
                        if (key === 'status') setStatus(val || 'all');
                        if (key === 'assigned') setAssigned(val);
                    }}
                    filters={[
                        { key: 'source', label: 'Source', options: SOURCES.map((s) => ({ ...s, label: `${s.label} (${counts.bySource[s.value] ?? 0})` })) },
                        { key: 'status', label: 'Status', options: statusOptions },
                        ...(assignees.length > 0 ? [{
                            key: 'assigned',
                            label: 'Assigned',
                            options: [{ value: '__unassigned__', label: 'Unassigned' }, ...assignees.map((name) => ({ value: name, label: name }))],
                        }] : []),
                    ]}
                    dateRange={{ from: dateFrom, to: dateTo, onChange: (which, val) => (which === 'from' ? setDateFrom(val) : setDateTo(val)) }}
                />
            </div>

            {/* Live search also has an immediate feel — filter as you type,
                the search box's own submit is just for keyboard Enter. */}

            {filtered.length === 0 ? (
                <EmptyBoard />
            ) : view === 'board' ? (
                <BoardView
                    columns={boardColumns}
                    orders={filtered}
                    currency={currency}
                    onOpen={(o) => setKey(`${o.type}:${o.id}`)}
                    onAction={updateStatus}
                    busyKey={busy}
                />
            ) : (
                <TableView
                    orders={filtered}
                    currency={currency}
                    onOpen={(o) => setKey(`${o.type}:${o.id}`)}
                />
            )}

            <OrderDrawer
                order={selected}
                currency={currency}
                busy={busy}
                can={can}
                onClose={() => setKey(null)}
                onUpdateStatus={updateStatus}
            />

            <ConfirmationModal
                request={confirmFor}
                cities={cities}
                onCancel={() => setConfirm(null)}
                onConfirm={(payload) => {
                    post(confirmFor.order, confirmFor.transition.value, null, payload);
                    setConfirm(null);
                }}
            />

            <ReasonModal
                request={reasonFor}
                onCancel={() => setReason(null)}
                onConfirm={(reason) => {
                    post(reasonFor.order, reasonFor.transition.value, reason);
                    setReason(null);
                }}
            />
        </SaasLayout>
    );
}

/* ----------------------------------------------------------------------- */
/* Board (Kanban) view                                                     */
/* ----------------------------------------------------------------------- */

/* Tailwind needs whole class names, so the lane count maps to a literal. */
const GRID_COLS = {
    1: 'xl:grid-cols-1', 2: 'xl:grid-cols-2', 3: 'xl:grid-cols-3',
    4: 'xl:grid-cols-4', 5: 'xl:grid-cols-5', 6: 'xl:grid-cols-6',
};

function BoardView({ columns, orders, currency, onOpen, onAction, busyKey }) {
    return (
        <div className={`grid grid-cols-1 sm:grid-cols-2 ${GRID_COLS[columns.length] ?? 'xl:grid-cols-5'} gap-4`}>
            {columns.map((col) => {
                const cards = orders.filter((o) => o.status === col.value);
                return (
                    <div key={col.value} className="flex flex-col min-w-0">
                        <div className="flex items-center justify-between px-1 mb-2.5">
                            <span className="inline-flex items-center gap-1.5 text-sm font-semibold text-content">
                                <span className={`w-2 h-2 rounded-full ${DOT[col.color]}`} />
                                {col.label}
                            </span>
                            <span className="min-w-5 px-1.5 rounded-full bg-surface-2 border border-line text-[11px] tabular-nums text-content-muted">
                                {cards.length}
                            </span>
                        </div>
                        <div className="flex flex-col gap-2.5 rounded-[var(--radius-card)] bg-surface-2/40 border border-dashed border-line p-2 min-h-24">
                            {cards.length === 0 ? (
                                <p className="py-6 text-center text-xs text-content-muted">No orders</p>
                            ) : (
                                cards.map((o) => (
                                    <OrderCard
                                        key={`${o.type}:${o.id}`}
                                        order={o}
                                        currency={currency}
                                        onClick={() => onOpen(o)}
                                        onAction={onAction}
                                        busy={busyKey === `${o.type}:${o.id}`}
                                    />
                                ))
                            )}
                        </div>
                    </div>
                );
            })}
        </div>
    );
}

function OrderCard({ order, currency, onClick, onAction, busy }) {
    const primaryTransition = (order.transitions ?? [])[0];

    return (
        <div className="w-full text-left bg-surface border border-line rounded-[var(--radius-card)] p-3 hover:border-primary/50 hover:shadow-sm transition group">
            <button onClick={onClick} className="w-full text-left focus:outline-none">
                <div className="flex items-center justify-between gap-2">
                    <span className="font-mono text-xs text-content-muted truncate">{order.reference}</span>
                    <SourceBadge order={order} />
                </div>
                <div className="mt-1.5 text-sm font-medium text-content truncate">
                    {order.customer_name || <span className="text-content-muted">Walk-in</span>}
                </div>
                <div className="mt-1.5 flex flex-wrap items-center gap-x-3 gap-y-1 text-[11px] text-content-muted">
                    {order.shipping_city_name && (
                        <span className="inline-flex items-center gap-1"><MapPin className="w-3 h-3" /> {order.shipping_city_name}</span>
                    )}
                    {order.assignee_name && (
                        <span className="inline-flex items-center gap-1"><UserCircle2 className="w-3 h-3" /> {order.assignee_name}</span>
                    )}
                </div>
                <div className="mt-2 flex items-center justify-between">
                    <span className="text-sm font-semibold tabular-nums text-content">{fmtMoney(order.total, currency)}</span>
                    <span className="inline-flex items-center gap-1 text-[11px] text-content-muted">
                        <Clock className="w-3 h-3" /> {timeAgo(order.created_at)}
                    </span>
                </div>
            </button>

            {primaryTransition && (
                <button
                    type="button"
                    disabled={busy}
                    onClick={(e) => { e.stopPropagation(); onAction(order, primaryTransition); }}
                    className="mt-2.5 flex w-full items-center justify-center gap-1.5 rounded-[var(--radius-button)] bg-primary-soft px-2.5 py-1.5 text-xs font-semibold text-primary hover:brightness-95 disabled:opacity-50 transition"
                >
                    {busy ? <Loader2 className="w-3.5 h-3.5 animate-spin" /> : null}
                    {primaryTransition.action}
                </button>
            )}
        </div>
    );
}

/* ----------------------------------------------------------------------- */
/* Table view                                                              */
/* ----------------------------------------------------------------------- */

function TableView({ orders, currency, onOpen }) {
    return (
        <div className="table-container">
            <div className="overflow-x-auto">
                <table className="w-full text-sm">
                    <thead>
                        <tr>
                            <th className="table-th">Reference</th>
                            <th className="table-th">Source</th>
                            <th className="table-th">Customer</th>
                            <th className="table-th">Status</th>
                            <th className="table-th">Stage</th>
                            <th className="table-th text-right">Amount</th>
                            <th className="table-th">Assigned</th>
                            <th className="table-th">Created</th>
                            <th className="table-th text-right">Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        {orders.map((o) => (
                            <tr key={`${o.type}:${o.id}`} className="table-row">
                                <td className="table-td font-mono text-xs text-content-muted">{o.reference}</td>
                                <td className="table-td"><SourceBadge order={o} /></td>
                                <td className="table-td">
                                    <div className="text-content">{o.customer_name || <span className="text-content-muted">Walk-in</span>}</div>
                                    {o.customer_email && <div className="text-xs text-content-muted">{o.customer_email}</div>}
                                </td>
                                <td className="table-td"><StatusBadge type="fulfillment" status={o.status} label={o.status_label} /></td>
                                <td className="table-td text-xs text-content-muted capitalize">{o.phase}</td>
                                <td className="table-td text-right font-semibold tabular-nums text-content">{fmtMoney(o.total, currency)}</td>
                                <td className="table-td text-xs text-content-muted">{o.assignee_name ?? '—'}</td>
                                <td className="table-td text-xs text-content-muted">{timeAgo(o.created_at)}</td>
                                <td className="table-td text-right">
                                    <button
                                        onClick={() => onOpen(o)}
                                        className="inline-flex items-center gap-1.5 px-2.5 py-1.5 text-xs font-medium rounded-[var(--radius-button)] bg-surface border border-line text-content hover:bg-surface-3 transition"
                                    >
                                        <Eye className="w-3.5 h-3.5" /> Manage
                                    </button>
                                </td>
                            </tr>
                        ))}
                    </tbody>
                </table>
            </div>
        </div>
    );
}

/* ----------------------------------------------------------------------- */
/* Slide-over drawer — details + status actions                           */
/* ----------------------------------------------------------------------- */

function OrderDrawer({ order, currency, busy, can, onClose, onUpdateStatus }) {
    const open = Boolean(order);

    // Close on Escape.
    useEffect(() => {
        if (!open) return;
        const onKey = (e) => e.key === 'Escape' && onClose();
        window.addEventListener('keydown', onKey);
        return () => window.removeEventListener('keydown', onKey);
    }, [open, onClose]);

    const key = order ? `${order.type}:${order.id}` : null;
    const isBusy = key && busy === key;

    return (
        <div className={`fixed inset-0 z-40 ${open ? '' : 'pointer-events-none'}`} aria-hidden={!open}>
            {/* Overlay */}
            <div
                onClick={onClose}
                className={`absolute inset-0 bg-black/40 backdrop-blur-sm transition-opacity duration-300 ${open ? 'opacity-100' : 'opacity-0'}`}
            />
            {/* Panel */}
            <aside
                className={`absolute right-0 inset-y-0 w-full max-w-md bg-surface border-l border-line shadow-2xl flex flex-col transition-transform duration-300 ease-out ${open ? 'translate-x-0' : 'translate-x-full'}`}
            >
                {order && (
                    <>
                        <header className="flex items-start justify-between gap-3 px-5 py-4 border-b border-line">
                            <div className="min-w-0">
                                <div className="flex items-center gap-2">
                                    <SourceBadge order={order} />
                                    <StatusBadge type="fulfillment" status={order.status} label={order.status_label} />
                                </div>
                                <h2 className="mt-2 font-mono text-sm text-content truncate">{order.reference}</h2>
                            </div>
                            <button
                                onClick={onClose}
                                className="shrink-0 p-1.5 rounded-[var(--radius-button)] text-content-muted hover:text-content hover:bg-surface-2 transition"
                                aria-label="Close"
                            >
                                <X className="w-5 h-5" />
                            </button>
                        </header>

                        <div className="flex-1 overflow-y-auto px-5 py-4 space-y-5">
                            {/* Customer */}
                            <Section title="Customer">
                                <InfoRow icon={User}  label="Name"  value={order.customer_name || 'Walk-in'} />
                                {order.customer_phone && <InfoRow icon={Phone} label="Phone" value={order.customer_phone} />}
                                {order.customer_email && <InfoRow icon={Mail}  label="Email" value={order.customer_email} />}
                                <InfoRow icon={Truck} label="Fulfillment" value={order.fulfillment_label ?? order.source_label} />
                                {order.shipping_city_name && <InfoRow icon={MapPin} label="City" value={order.shipping_city_name} />}
                                {order.assignee_name && <InfoRow icon={UserCircle2} label="Assigned to" value={order.assignee_name} />}
                                {order.payment_method && (
                                    <InfoRow icon={CreditCard} label="Payment" value={<span className="uppercase text-xs tracking-wider">{order.payment_method}</span>} />
                                )}
                                {order.source === 'online' && order.connection_label && (
                                    <InfoRow
                                        icon={Globe}
                                        label="Store"
                                        value={`${order.connection_label}${order.external_order_number ? ` · #${order.external_order_number}` : ''}`}
                                    />
                                )}
                            </Section>

                            {/* Items */}
                            <Section title={`Items (${order.items?.length ?? 0})`}>
                                {(order.items ?? []).length === 0 ? (
                                    <p className="text-sm text-content-muted">No line items.</p>
                                ) : (
                                    <ul className="divide-y divide-line/50 -my-2">
                                        {order.items.map((it, i) => (
                                            <li key={i} className="flex items-center justify-between gap-3 py-2">
                                                <div className="min-w-0 flex items-start gap-2.5">
                                                    <span className="mt-0.5 shrink-0 inline-flex items-center justify-center w-6 h-6 rounded-md bg-surface-2 border border-line text-[11px] font-medium tabular-nums text-content-muted">
                                                        {fmtQty(it.quantity)}
                                                    </span>
                                                    <div className="min-w-0">
                                                        <div className="text-sm text-content truncate">{it.name}</div>
                                                        {it.sku && <div className="text-[11px] font-mono text-content-muted truncate">{it.sku}</div>}
                                                    </div>
                                                </div>
                                                <span className="shrink-0 text-sm tabular-nums font-medium text-content">{fmtMoney(it.line_total, currency)}</span>
                                            </li>
                                        ))}
                                    </ul>
                                )}
                            </Section>

                            {/* Totals */}
                            <Section title="Summary">
                                <TotalRow label="Subtotal" value={fmtMoney(order.subtotal, currency)} />
                                {order.discount > 0 && <TotalRow label="Discount" value={`−${fmtMoney(order.discount, currency)}`} tone="red" />}
                                {order.tax > 0 && <TotalRow label="Tax" value={fmtMoney(order.tax, currency)} />}
                                <div className="pt-2 mt-1 border-t border-line flex items-center justify-between">
                                    <span className="text-sm font-semibold text-content">Total</span>
                                    <span className="text-lg font-bold tabular-nums text-content">{fmtMoney(order.total, currency)}</span>
                                </div>
                            </Section>
                        </div>

                        {/* Action footer — dynamic status transitions */}
                        <footer className="border-t border-line px-5 py-4 bg-surface-2/40">
                            <p className="text-xs text-content-muted mb-2.5">
                                {order.transitions?.length ? 'Move this order to:' : 'This order has reached a final state.'}
                            </p>
                            <div className="flex flex-wrap gap-2">
                                {(order.transitions ?? []).map((t) => {
                                    const danger  = t.value === 'cancelled' || t.value === 'returned';
                                    // The server re-checks this; hiding the button
                                    // just avoids offering a guaranteed 403.
                                    const allowed = can(t.permission) || can('orders.manage');
                                    if (! allowed) return null;

                                    return (
                                        <button
                                            key={t.value}
                                            disabled={isBusy}
                                            onClick={() => onUpdateStatus(order, t)}
                                            className={[
                                                'inline-flex items-center gap-1.5 px-3 py-2 text-sm font-semibold rounded-[var(--radius-button)] transition disabled:opacity-50',
                                                danger
                                                    ? 'bg-surface border border-danger/40 text-danger hover:bg-danger-soft'
                                                    : 'bg-primary text-primary-contrast hover:bg-primary-strong',
                                            ].join(' ')}
                                        >
                                            {isBusy ? <Loader2 className="w-4 h-4 animate-spin" /> : <Package className="w-4 h-4" />}
                                            {t.action}
                                        </button>
                                    );
                                })}
                            </div>

                            {order.phase === 'returns' && (
                                <Link
                                    href="/dashboard/orders/returns"
                                    className="mt-3 inline-flex items-center gap-1.5 px-3 py-2 text-xs font-medium rounded-[var(--radius-button)] bg-surface border border-line text-content-muted hover:text-content hover:bg-surface-3 transition"
                                >
                                    <Undo2 className="w-3.5 h-3.5" /> Open inspection worksheet
                                </Link>
                            )}
                            {order.type === 'pos' && (
                                <div className="mt-3 flex items-center gap-2">
                                    <Link
                                        href={`/dashboard/orders/${order.reference}`}
                                        className="inline-flex items-center gap-1.5 px-3 py-2 text-xs font-medium rounded-[var(--radius-button)] bg-surface border border-line text-content-muted hover:text-content hover:bg-surface-3 transition"
                                    >
                                        <Eye className="w-3.5 h-3.5" /> Full details
                                    </Link>
                                    <a
                                        href={`/dashboard/orders/${order.reference}/receipt`}
                                        target="_blank"
                                        rel="noopener"
                                        className="inline-flex items-center gap-1.5 px-3 py-2 text-xs font-medium rounded-[var(--radius-button)] bg-surface border border-line text-content-muted hover:text-content hover:bg-surface-3 transition"
                                    >
                                        <Printer className="w-3.5 h-3.5" /> Receipt
                                    </a>
                                </div>
                            )}
                            {order.type === 'online' && (
                                <div className="mt-3 flex items-center gap-2">
                                    <Link
                                        href={`/dashboard/orders/online/${order.id}`}
                                        className="inline-flex items-center gap-1.5 px-3 py-2 text-xs font-medium rounded-[var(--radius-button)] bg-surface border border-line text-content-muted hover:text-content hover:bg-surface-3 transition"
                                    >
                                        <Eye className="w-3.5 h-3.5" /> Full details
                                    </Link>
                                    <a
                                        href={`/dashboard/orders/online/${order.id}/receipt`}
                                        target="_blank"
                                        rel="noopener"
                                        className="inline-flex items-center gap-1.5 px-3 py-2 text-xs font-medium rounded-[var(--radius-button)] bg-surface border border-line text-content-muted hover:text-content hover:bg-surface-3 transition"
                                    >
                                        <Printer className="w-3.5 h-3.5" /> Receipt
                                    </a>
                                </div>
                            )}
                        </footer>
                    </>
                )}
            </aside>
        </div>
    );
}

/* ----------------------------------------------------------------------- */
/* Confirmation data correction — canonical city drives warehouse routing   */
/* ----------------------------------------------------------------------- */
function ConfirmationModal({ request, cities, onCancel, onConfirm }) {
    const open = Boolean(request);
    const order = request?.order;
    const [cityId, setCityId] = useState('');
    const [address, setAddress] = useState('');

    useEffect(() => {
        if (!open) return;
        setCityId(order?.shipping_city_id ?? '');
        setAddress(order?.delivery_address ?? '');
    }, [open, order]);

    if (!open) return null;

    return (
        <div className="fixed inset-0 z-50 flex items-center justify-center p-4">
            <div onClick={onCancel} className="absolute inset-0 bg-black/50 backdrop-blur-sm" />
            <div className="relative w-full max-w-md bg-surface border border-line rounded-[var(--radius-card)] shadow-2xl p-5">
                <h3 className="text-base font-semibold text-content">Confirm order details</h3>
                <p className="mt-1 text-sm text-content-muted">The selected city is used to choose the warehouse. The original platform address stays untouched.</p>
                <div className="mt-4 space-y-3">
                    <div>
                        <label className="block text-xs font-medium text-content-muted mb-1.5">City</label>
                        <select value={cityId} onChange={(e) => setCityId(e.target.value)} className="w-full px-3 py-2 text-sm rounded-[var(--radius-button)] bg-surface-2 border border-line text-content">
                            <option value="">Use default warehouse</option>
                            {cities.map((city) => <option key={city.id} value={city.id}>{city.name}{city.region ? ` — ${city.region}` : ''}</option>)}
                        </select>
                    </div>
                    <div>
                        <label className="block text-xs font-medium text-content-muted mb-1.5">Confirmed address</label>
                        <textarea rows={3} value={address} onChange={(e) => setAddress(e.target.value)} className="w-full px-3 py-2 text-sm rounded-[var(--radius-button)] bg-surface-2 border border-line text-content" />
                    </div>
                </div>
                <div className="mt-5 flex justify-end gap-2">
                    <button onClick={onCancel} className="px-3 py-2 text-sm rounded-[var(--radius-button)] bg-surface-2 border border-line text-content-muted">Cancel</button>
                    <button onClick={() => onConfirm({ shipping_city_id: cityId || null, shipping_address: address || null })} className="px-3 py-2 text-sm font-semibold rounded-[var(--radius-button)] bg-primary text-primary-contrast hover:bg-primary-strong">Confirm order</button>
                </div>
            </div>
        </div>
    );
}

/* ----------------------------------------------------------------------- */
/* Reason modal — cancelling and returning must be justified                */
/* ----------------------------------------------------------------------- */

/* Mirrors OrderReturn::reasons(); anything else is stored as free text under
   the `other` reason. */
const RETURN_REASONS = [
    { value: 'refused',            label: 'Refused on delivery' },
    { value: 'damaged_in_transit', label: 'Damaged in transit' },
    { value: 'wrong_item',         label: 'Wrong item sent' },
    { value: 'customer_remorse',   label: 'Customer changed their mind' },
    { value: 'other',              label: 'Other' },
];

function ReasonModal({ request, onCancel, onConfirm }) {
    const open = Boolean(request);
    const isReturn = request?.transition?.value === 'returned';

    const [preset, setPreset] = useState('refused');
    const [text, setText]     = useState('');

    useEffect(() => {
        if (open) { setPreset('refused'); setText(''); }
    }, [open]);

    useEffect(() => {
        if (!open) return;
        const onKey = (e) => e.key === 'Escape' && onCancel();
        window.addEventListener('keydown', onKey);
        return () => window.removeEventListener('keydown', onKey);
    }, [open, onCancel]);

    if (! open) return null;

    // A return sends a catalogued reason code; a cancellation is free text.
    const value = isReturn ? (preset === 'other' ? text.trim() : preset) : text.trim();
    const valid = value.length > 0;

    return (
        <div className="fixed inset-0 z-50 flex items-center justify-center p-4">
            <div onClick={onCancel} className="absolute inset-0 bg-black/50 backdrop-blur-sm" />
            <div className="relative w-full max-w-md bg-surface border border-line rounded-[var(--radius-card)] shadow-2xl p-5">
                <div className="flex items-start gap-3">
                    <span className="shrink-0 inline-flex items-center justify-center w-9 h-9 rounded-[var(--radius-button)] bg-danger-soft text-danger">
                        <AlertTriangle className="w-5 h-5" />
                    </span>
                    <div className="min-w-0">
                        <h3 className="text-base font-semibold text-content">{request.transition.action}</h3>
                        <p className="mt-0.5 text-sm text-content-muted">
                            {isReturn
                                ? 'Recording a return does not move any stock yet — an inspector decides where each item goes.'
                                : 'Cancelling releases any stock committed to this order.'}
                        </p>
                    </div>
                </div>

                <div className="mt-4 space-y-3">
                    {isReturn && (
                        <div>
                            <label className="block text-xs font-medium text-content-muted mb-1.5">Reason</label>
                            <select
                                value={preset}
                                onChange={(e) => setPreset(e.target.value)}
                                className="w-full px-3 py-2 text-sm rounded-[var(--radius-button)] bg-surface-2 border border-line text-content focus:outline-none focus:ring-2 focus:ring-primary/40"
                            >
                                {RETURN_REASONS.map((r) => (
                                    <option key={r.value} value={r.value}>{r.label}</option>
                                ))}
                            </select>
                        </div>
                    )}

                    {(! isReturn || preset === 'other') && (
                        <div>
                            <label className="block text-xs font-medium text-content-muted mb-1.5">
                                {isReturn ? 'Details' : 'Why is this order being cancelled?'}
                            </label>
                            <textarea
                                autoFocus
                                rows={3}
                                value={text}
                                onChange={(e) => setText(e.target.value)}
                                placeholder="Recorded on the order's audit trail…"
                                className="w-full px-3 py-2 text-sm rounded-[var(--radius-button)] bg-surface-2 border border-line text-content placeholder:text-content-muted focus:outline-none focus:ring-2 focus:ring-primary/40"
                            />
                        </div>
                    )}
                </div>

                <div className="mt-5 flex justify-end gap-2">
                    <button
                        onClick={onCancel}
                        className="px-3 py-2 text-sm font-medium rounded-[var(--radius-button)] bg-surface-2 border border-line text-content-muted hover:text-content transition"
                    >
                        Never mind
                    </button>
                    <button
                        disabled={! valid}
                        onClick={() => onConfirm(value)}
                        className="px-3 py-2 text-sm font-semibold rounded-[var(--radius-button)] bg-danger text-white hover:brightness-90 disabled:opacity-40 disabled:cursor-not-allowed transition"
                    >
                        {request.transition.action}
                    </button>
                </div>
            </div>
        </div>
    );
}

/* ----------------------------------------------------------------------- */
/* Small building blocks                                                   */
/* ----------------------------------------------------------------------- */

function ViewToggle({ view, onChange }) {
    const opts = [
        { value: 'board', icon: LayoutGrid, label: 'Board' },
        { value: 'table', icon: Table2,     label: 'Table' },
    ];
    return (
        <div className="inline-flex items-center p-1 rounded-[var(--radius-button)] bg-surface-2 border border-line">
            {opts.map((o) => {
                const Icon = o.icon;
                const isActive = view === o.value;
                return (
                    <button
                        key={o.value}
                        onClick={() => onChange(o.value)}
                        title={o.label}
                        className={[
                            'inline-flex items-center gap-1.5 px-2.5 py-1.5 text-sm font-medium rounded-[var(--radius-button)] transition',
                            isActive ? 'bg-surface text-content shadow-sm' : 'text-content-muted hover:text-content',
                        ].join(' ')}
                    >
                        <Icon className="w-4 h-4" />
                        <span className="hidden sm:inline">{o.label}</span>
                    </button>
                );
            })}
        </div>
    );
}

function SourceBadge({ order }) {
    const tone = SOURCE_TONE[order.source] ?? SOURCE_TONE.pos;
    return (
        <span className={`inline-flex items-center px-2 py-0.5 rounded-full text-[11px] font-medium whitespace-nowrap ${tone}`}>
            {order.source_label}
        </span>
    );
}

function Section({ title, children }) {
    return (
        <div>
            <h3 className="text-[11px] font-semibold uppercase tracking-wider text-content-muted mb-2">{title}</h3>
            <div className="space-y-2">{children}</div>
        </div>
    );
}

function InfoRow({ icon: Icon, label, value }) {
    return (
        <div className="flex items-center justify-between gap-3 text-sm">
            <span className="inline-flex items-center gap-1.5 text-content-muted">
                {Icon && <Icon className="w-3.5 h-3.5" />} {label}
            </span>
            <span className="text-content text-right truncate">{value}</span>
        </div>
    );
}

function TotalRow({ label, value, tone }) {
    const toneClass = tone === 'red' ? 'text-danger' : 'text-content';
    return (
        <div className="flex items-center justify-between text-sm">
            <span className="text-content-muted">{label}</span>
            <span className={`tabular-nums ${toneClass}`}>{value}</span>
        </div>
    );
}

function EmptyBoard() {
    return (
        <div className="bg-surface-2 border border-line rounded-[var(--radius-card)] py-16 flex flex-col items-center text-center">
            <div className="w-12 h-12 rounded-full bg-surface-3 flex items-center justify-center mb-3">
                <ShoppingCart className="w-6 h-6 text-content-muted" />
            </div>
            <p className="text-sm font-medium text-content">No orders match this view</p>
            <p className="text-xs text-content-muted mt-1">Try a different filter or clear your search.</p>
        </div>
    );
}

/* ----------------------------------------------------------------------- */
/* Helpers                                                                 */
/* ----------------------------------------------------------------------- */

function fmtMoney(value, currency) {
    const n = Number(value) || 0;
    return `${currency} ${n.toLocaleString('en-US', { minimumFractionDigits: 2, maximumFractionDigits: 2 })}`;
}

function fmtQty(q) {
    const n = Number(q) || 0;
    return Number.isInteger(n) ? String(n) : n.toString();
}

function timeAgo(iso) {
    if (!iso) return '—';
    const then = new Date(iso).getTime();
    if (Number.isNaN(then)) return '—';
    const secs = Math.round((Date.now() - then) / 1000);
    if (secs < 60) return 'just now';
    const mins = Math.round(secs / 60);
    if (mins < 60) return `${mins}m ago`;
    const hrs = Math.round(mins / 60);
    if (hrs < 24) return `${hrs}h ago`;
    const days = Math.round(hrs / 24);
    if (days < 7) return `${days}d ago`;
    return new Date(iso).toLocaleDateString();
}
