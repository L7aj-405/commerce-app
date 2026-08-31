import { useEffect, useMemo, useRef, useState } from 'react';
import { useForm, usePage } from '@inertiajs/react';
import { HandCoins, CheckCircle2, X, Truck, Users, ListChecks, Landmark, AlertTriangle, Clock, Zap } from 'lucide-react';
import SaasLayout from '@/Layouts/SaasLayout';
import DataTable from '@/Components/DataTable';
import Button from '@/Components/Button';
import EmptyState from '@/Components/EmptyState';
import { formatDateTime, formatDateOnly } from '@/Support/formatDate';

function money(amount, currency = 'MAD') {
    return `${Number(amount ?? 0).toLocaleString(undefined, { minimumFractionDigits: 2, maximumFractionDigits: 2 })} ${currency}`;
}

const STATUS_STYLE = {
    draft: 'bg-slate-500/15 text-slate-600 dark:text-slate-300',
    settled: 'bg-success-soft text-success',
    confirmed: 'bg-success-soft text-success',
    cancelled: 'bg-danger-soft text-danger',
    partial: 'bg-warning-soft text-warning',
    disputed: 'bg-danger-soft text-danger',
};

function StatusChip({ status }) {
    return <span className={`inline-flex px-2 py-0.5 rounded-full text-xs font-medium capitalize ${STATUS_STYLE[status] ?? 'bg-slate-500/15 text-slate-500'}`}>{status}</span>;
}

// Mirrors App\Enums\FinanceCodCollectabilityStatus — a COD receivable can
// exist (and show up here) well before it's actually collectable; this is
// what tells the accountant WHY an action is disabled. Label text itself
// comes from the backend (FinanceCodCollectabilityService::assess()'s
// `label` field, e.g. "Delivered — awaiting Ozon Express payout") since it
// can name the actual carrier — only the color mapping lives here.
const COLLECTABILITY_STYLE = {
    not_delivered: 'bg-slate-500/15 text-slate-600 dark:text-slate-300',
    with_internal_courier: 'bg-indigo-500/15 text-indigo-700 dark:text-indigo-300',
    with_external_carrier: 'bg-blue-500/15 text-blue-700 dark:text-blue-300',
    delivered_collectable: 'bg-success-soft text-success',
    delivered_awaiting_provider_payout: 'bg-blue-500/15 text-blue-700 dark:text-blue-300',
    delivered_awaiting_courier_deposit: 'bg-indigo-500/15 text-indigo-700 dark:text-indigo-300',
    settled: 'bg-slate-500/15 text-slate-500',
    cancelled_or_returned: 'bg-danger-soft text-danger',
};

function CollectabilityChip({ status, label, reason }) {
    return (
        <span
            title={reason}
            className={`inline-flex px-2 py-0.5 rounded-full text-xs font-medium ${COLLECTABILITY_STYLE[status] ?? 'bg-slate-500/15 text-slate-500'}`}
        >
            {label ?? status}
        </span>
    );
}

/**
 * The ad-hoc "Mark collected" button is only ever shown ENABLED for a
 * genuine manual/direct-pickup delivery (o.is_directly_collectable) — the
 * same rule FinanceOrderTransactionService::markCodCollected() enforces
 * server-side, so this is a UX shortcut to the right workflow, never the
 * only thing standing between an external/courier order and a bypass.
 */
function CollectAction({ order: o, onDirectCollect, onViewSettlement, onGoToTab }) {
    if (o.collectability_status === 'delivered_awaiting_provider_payout') {
        return (
            <button
                type="button"
                onClick={() => onViewSettlement(o)}
                title={o.reason}
                className="inline-flex items-center gap-1.5 px-3 py-1.5 text-xs font-semibold rounded-lg bg-blue-500/15 text-blue-700 dark:text-blue-300 hover:brightness-95"
            >
                <Truck className="w-3.5 h-3.5" /> View settlement period
            </button>
        );
    }

    if (o.collectability_status === 'delivered_awaiting_courier_deposit') {
        return (
            <button
                type="button"
                onClick={() => onGoToTab('deposits')}
                title={o.reason}
                className="inline-flex items-center gap-1.5 px-3 py-1.5 text-xs font-semibold rounded-lg bg-indigo-500/15 text-indigo-700 dark:text-indigo-300 hover:brightness-95"
            >
                <Users className="w-3.5 h-3.5" /> Go to Courier Deposits
            </button>
        );
    }

    return (
        <button
            type="button"
            onClick={() => o.is_directly_collectable && onDirectCollect()}
            disabled={! o.is_directly_collectable}
            title={o.is_directly_collectable ? undefined : o.reason}
            className="inline-flex items-center gap-1.5 px-3 py-1.5 text-xs font-semibold rounded-lg bg-success-soft text-success hover:brightness-95 disabled:opacity-40 disabled:cursor-not-allowed disabled:hover:brightness-100"
        >
            <CheckCircle2 className="w-3.5 h-3.5" /> Mark collected
        </button>
    );
}

const TABS = [
    { key: 'pending', label: 'Pending COD', icon: HandCoins },
    { key: 'settlements', label: 'External settlements', icon: Truck },
    { key: 'deposits', label: 'Courier deposits', icon: Users },
];

const PERIOD_STATUS_STYLE = {
    accumulating: 'bg-slate-500/15 text-slate-600 dark:text-slate-300',
    ready_to_verify: 'bg-blue-500/15 text-blue-700 dark:text-blue-300',
    overdue: 'bg-danger-soft text-danger',
};

const PERIOD_STATUS_LABEL = {
    accumulating: 'Accumulating',
    ready_to_verify: 'Ready to verify',
    overdue: 'Overdue',
};

export default function Index({ orders, settlements, deposits, providerPeriods, accounts, stores, couriers, can }) {
    const permissions = usePage().props.auth?.permissions ?? [];
    const canCollect = permissions.includes('*') || permissions.includes('finance.mark_collected');
    const canManageSettlements = can?.manage_settlements ?? false;

    const [tab, setTab] = useState('pending');
    const [collecting, setCollecting] = useState(null); // ad-hoc single-order collect
    const [selected, setSelected] = useState(() => new Set());
    const [modal, setModal] = useState(null); // 'settlement' | 'deposit' | null
    const [reconcileTarget, setReconcileTarget] = useState(null); // { mode: 'period', period } | { mode: 'settlement', settlement }
    const [focusPeriodKey, setFocusPeriodKey] = useState(null); // provider_code + period_start of the period "View settlement period" jumped to
    const [diagnosingOrder, setDiagnosingOrder] = useState(null); // order whose settlement_diagnostics are being shown

    // "View settlement period" never leads to a silently empty tab: the
    // order either already belongs to a live period (jump + highlight it),
    // or the backend already said exactly why not (settlement_diagnostics)
    // — show that instead of switching to an empty-looking tab.
    const viewSettlement = (order) => {
        if (order.settlement_period) {
            setFocusPeriodKey(`${order.settlement_period.provider_code}-${order.settlement_period.period_start}`);
            setTab('settlements');
        } else {
            setDiagnosingOrder(order);
        }
    };

    const toggleSelected = (order) => {
        if (! order.is_collectable) return; // can't be included in a settlement/deposit until delivered
        setSelected((prev) => {
            const next = new Set(prev);
            if (next.has(order.id)) next.delete(order.id); else next.add(order.id);
            return next;
        });
    };

    const selectedOrders = useMemo(() => orders.filter((o) => selected.has(o.id)), [orders, selected]);
    const selectedTotal = useMemo(() => selectedOrders.reduce((sum, o) => sum + Number(o.total ?? 0), 0), [selectedOrders]);

    const carrierCell = (o) => {
        if (o.external_carrier) return <span className="inline-flex items-center gap-1 text-xs font-medium text-content"><Truck className="w-3.5 h-3.5 text-primary" /> {o.external_carrier}</span>;
        if (o.internal_courier) return <span className="inline-flex items-center gap-1 text-xs font-medium text-content"><Users className="w-3.5 h-3.5 text-primary" /> {o.internal_courier}</span>;
        return <span className="text-xs text-content-muted">Unassigned</span>;
    };

    const pendingColumns = [
        ...(canManageSettlements ? [{ key: 'select', label: '', render: (o) => (
            <input
                type="checkbox"
                checked={selected.has(o.id)}
                onChange={() => toggleSelected(o)}
                disabled={! o.is_collectable}
                title={o.is_collectable ? undefined : o.reason}
                className="w-4 h-4 rounded border-line disabled:opacity-40 disabled:cursor-not-allowed"
            />
        ) }] : []),
        { key: 'order_number', label: 'Order', render: (o) => (
            <div>
                <p className="font-medium text-content">{o.order_number}</p>
                <p className="text-xs text-content-muted">{o.customer_name}{o.customer_phone ? ` · ${o.customer_phone}` : ''}</p>
            </div>
        ) },
        { key: 'store', label: 'Store', render: (o) => o.store?.name ?? '—' },
        { key: 'created_at', label: 'Order date', render: (o) => <span className="whitespace-nowrap">{formatDateTime(o.created_at)}</span> },
        { key: 'carrier', label: 'Carrier / courier', render: carrierCell },
        { key: 'delivery_stage', label: 'Delivery stage', render: (o) => <span>{o.delivery_stage ?? '—'}</span> },
        { key: 'collectability', label: 'Collectability', render: (o) => <CollectabilityChip status={o.collectability_status} label={o.label} reason={o.reason} /> },
        { key: 'total', label: 'Amount', align: 'right', render: (o) => <span className="font-semibold tabular-nums text-content">{money(o.total, o.currency)}</span> },
        ...(canCollect ? [{ key: 'actions', label: '', align: 'right', render: (o) => (
            <CollectAction order={o} onDirectCollect={() => setCollecting(o)} onViewSettlement={viewSettlement} onGoToTab={setTab} />
        ) }] : []),
    ];

    const settlementColumns = [
        { key: 'settlement_date', label: 'Date', render: (s) => formatDateOnly(s.settlement_date) },
        { key: 'carrier_name', label: 'Carrier', render: (s) => s.provider?.name ?? s.carrier_name ?? '—' },
        { key: 'store', label: 'Store', render: (s) => s.store?.name ?? 'Organization' },
        { key: 'gross_cod_amount', label: 'Gross COD', align: 'right', render: (s) => money(s.gross_cod_amount) },
        { key: 'delivery_fees', label: 'Fees', align: 'right', render: (s) => <span className="text-danger">-{money(s.delivery_fees)}</span> },
        { key: 'net_received', label: 'Net / actual received', align: 'right', render: (s) => <span className="font-semibold text-success">{money(s.net_received)}</span> },
        { key: 'variance_amount', label: 'Variance', align: 'right', render: (s) => s.variance_amount == null || Math.abs(Number(s.variance_amount)) < 0.01 ? <span className="text-content-muted">—</span> : (
            <span className={Number(s.variance_amount) < 0 ? 'text-danger font-semibold' : 'text-warning font-semibold'}>{Number(s.variance_amount) > 0 ? '+' : ''}{money(s.variance_amount)}</span>
        ) },
        { key: 'account', label: 'Account', render: (s) => s.account?.name ?? '—' },
        { key: 'status', label: 'Status', render: (s) => <StatusChip status={s.status} /> },
        ...(canManageSettlements ? [{ key: 'actions', label: '', align: 'right', render: (s) => (
            s.status === 'draft' ? (
                <div className="flex justify-end gap-2">
                    <Button variant="secondary" onClick={() => setReconcileTarget({ mode: 'settlement', settlement: s })}>Reconcile</Button>
                    <SettleButton settlement={s} />
                    <CancelButton url={`/dashboard/finance/cod-settlements/${s.id}/cancel`} />
                </div>
            ) : null
        ) }] : []),
    ];

    const depositColumns = [
        { key: 'deposit_date', label: 'Date', render: (d) => formatDateOnly(d.deposit_date) },
        { key: 'courier', label: 'Courier', render: (d) => d.courier?.name ?? '—' },
        { key: 'store', label: 'Store', render: (d) => d.store?.name ?? 'Organization' },
        { key: 'expected_amount', label: 'Expected', align: 'right', render: (d) => money(d.expected_amount) },
        { key: 'cash_received', label: 'Cash received', align: 'right', render: (d) => money(d.cash_received) },
        { key: 'difference', label: 'Difference', align: 'right', render: (d) => (
            <span className={Number(d.difference) < 0 ? 'text-danger font-semibold' : Number(d.difference) > 0 ? 'text-warning font-semibold' : 'text-content-muted'}>
                {Number(d.difference) > 0 ? '+' : ''}{money(d.difference)}
            </span>
        ) },
        { key: 'account', label: 'Account', render: (d) => d.account?.name ?? '—' },
        { key: 'status', label: 'Status', render: (d) => <StatusChip status={d.status} /> },
        ...(canManageSettlements ? [{ key: 'actions', label: '', align: 'right', render: (d) => (
            d.status === 'draft' ? (
                <div className="flex justify-end gap-2">
                    <ConfirmButton url={`/dashboard/finance/courier-deposits/${d.id}/confirm`} />
                    <CancelButton url={`/dashboard/finance/courier-deposits/${d.id}/cancel`} />
                </div>
            ) : null
        ) }] : []),
    ];

    return (
        <SaasLayout pageHeader={{
            title: 'COD receivables',
            subtitle: 'Cash-on-delivery orders, external carrier settlements & courier cash deposits',
            breadcrumbs: [{ label: 'Dashboard', href: '/dashboard' }, { label: 'Finance', href: '/dashboard/finance' }, { label: 'COD Receivables' }],
        }}>
            <div className="mb-4 flex flex-wrap items-center gap-2">
                {TABS.map((t) => (
                    <button
                        key={t.key}
                        type="button"
                        onClick={() => setTab(t.key)}
                        className={`inline-flex items-center gap-1.5 px-3.5 py-2 text-sm font-semibold rounded-lg transition ${tab === t.key ? 'bg-primary text-primary-contrast' : 'bg-surface-2 border border-line text-content-muted hover:text-content'}`}
                    >
                        <t.icon className="w-4 h-4" /> {t.label}
                        {t.key === 'settlements' && settlements.length > 0 && <span className="opacity-70">({settlements.length})</span>}
                        {t.key === 'deposits' && deposits.length > 0 && <span className="opacity-70">({deposits.length})</span>}
                        {t.key === 'pending' && orders.length > 0 && <span className="opacity-70">({orders.length})</span>}
                    </button>
                ))}
            </div>

            {tab === 'pending' && (
                <>
                    {canManageSettlements && selected.size > 0 && (
                        <div className="mb-4 flex flex-wrap items-center justify-between gap-3 rounded-xl border border-primary/30 bg-primary/5 px-4 py-3">
                            <div className="flex items-center gap-2 text-sm text-content">
                                <ListChecks className="w-4 h-4 text-primary" />
                                <span>{selected.size} order(s) selected · <span className="font-semibold tabular-nums">{money(selectedTotal)}</span></span>
                            </div>
                            <div className="flex gap-2">
                                <Button variant="secondary" onClick={() => setModal('settlement')}>Create external settlement</Button>
                                <Button variant="secondary" onClick={() => setModal('deposit')}>Create courier deposit</Button>
                                <button type="button" onClick={() => setSelected(new Set())} className="text-xs text-content-muted hover:text-content px-2">Clear</button>
                            </div>
                        </div>
                    )}

                    <DataTable columns={pendingColumns} data={orders} emptyIcon={HandCoins} emptyMessage="No COD orders pending collection." />

                    {collecting && <CollectModal order={collecting} accounts={accounts} onClose={() => setCollecting(null)} />}
                    {modal === 'settlement' && (
                        <SettlementModal
                            orders={selectedOrders}
                            accounts={accounts}
                            stores={stores}
                            onClose={() => setModal(null)}
                            onDone={() => { setModal(null); setSelected(new Set()); }}
                        />
                    )}
                    {modal === 'deposit' && (
                        <DepositModal
                            orders={selectedOrders}
                            accounts={accounts}
                            stores={stores}
                            couriers={couriers}
                            onClose={() => setModal(null)}
                            onDone={() => { setModal(null); setSelected(new Set()); }}
                        />
                    )}
                </>
            )}

            {tab === 'settlements' && (
                <>
                    <h3 className="text-sm font-semibold text-content mb-3">Payout periods</h3>
                    {providerPeriods.length === 0 ? (
                        <div className="mb-6"><EmptyState icon={Clock} title="Nothing accumulating right now" description="Delivered COD orders for a configured provider will group into a payout period here." /></div>
                    ) : (
                        <div className="grid grid-cols-1 sm:grid-cols-2 xl:grid-cols-3 gap-4 mb-6">
                            {providerPeriods.map((p) => {
                                const key = `${p.provider_code}-${p.period_start}`;
                                return (
                                    <PeriodCard
                                        key={key}
                                        period={p}
                                        canManage={canManageSettlements}
                                        highlighted={focusPeriodKey === key}
                                        onVerify={() => setReconcileTarget({ mode: 'period', period: p })}
                                    />
                                );
                            })}
                        </div>
                    )}

                    <h3 className="text-sm font-semibold text-content mb-3">Settlement history</h3>
                    <DataTable columns={settlementColumns} data={settlements} emptyIcon={Truck} emptyMessage="No external carrier settlements yet — select pending COD orders on the Pending tab to create one, or verify a payout period above." />
                </>
            )}

            {reconcileTarget && (
                <ReconcileModal target={reconcileTarget} accounts={accounts} onClose={() => setReconcileTarget(null)} onDone={() => setReconcileTarget(null)} />
            )}

            {diagnosingOrder && (
                <SettlementDiagnosticsModal
                    order={diagnosingOrder}
                    canRecalculate={can?.recalculate_settlement ?? false}
                    onClose={() => setDiagnosingOrder(null)}
                />
            )}

            {tab === 'deposits' && (
                <DataTable columns={depositColumns} data={deposits} emptyIcon={Users} emptyMessage="No courier cash deposits yet — select pending COD orders on the Pending tab to create one." />
            )}
        </SaasLayout>
    );
}

function SettleButton({ settlement }) {
    const { post, processing } = useForm({});
    return (
        <button
            type="button"
            disabled={processing}
            onClick={() => post(`/dashboard/finance/cod-settlements/${settlement.id}/settle`, { preserveScroll: true })}
            className="px-2.5 py-1 text-xs font-semibold rounded-lg bg-success-soft text-success hover:brightness-95 disabled:opacity-50"
        >
            {processing ? 'Settling…' : 'Settle'}
        </button>
    );
}

function ConfirmButton({ url }) {
    const { post, processing } = useForm({});
    return (
        <button
            type="button"
            disabled={processing}
            onClick={() => post(url, { preserveScroll: true })}
            className="px-2.5 py-1 text-xs font-semibold rounded-lg bg-success-soft text-success hover:brightness-95 disabled:opacity-50"
        >
            {processing ? 'Confirming…' : 'Confirm'}
        </button>
    );
}

function CancelButton({ url }) {
    const { post, processing } = useForm({});
    return (
        <button
            type="button"
            disabled={processing}
            onClick={() => post(url, { preserveScroll: true })}
            className="px-2.5 py-1 text-xs font-semibold rounded-lg bg-danger-soft text-danger hover:brightness-95 disabled:opacity-50"
        >
            {processing ? '…' : 'Cancel'}
        </button>
    );
}

function CollectModal({ order, accounts, onClose }) {
    const { data, setData, post, processing, errors } = useForm({
        account_id: accounts.find((a) => a.type === 'cash')?.id ?? (accounts[0]?.id ?? ''),
        amount_collected: order.total,
        collected_at: new Date().toISOString().slice(0, 10),
        reference: '',
        note: '',
    });

    const submit = (e) => {
        e.preventDefault();
        post(`/dashboard/finance/cod-receivables/${order.id}/mark-collected`, { onSuccess: onClose, preserveScroll: true });
    };

    return (
        <Modal title={`Mark COD collected — ${order.order_number}`} onClose={onClose}>
            <form onSubmit={submit} className="space-y-4">
                <Field label="Account" required error={errors.account_id}>
                    <select value={data.account_id} onChange={(e) => setData('account_id', e.target.value)} className={inputClass(errors.account_id)}>
                        <option value="">Select an account</option>
                        {accounts.map((a) => <option key={a.id} value={a.id}>{a.name}</option>)}
                    </select>
                </Field>
                <div className="grid grid-cols-2 gap-3">
                    <Field label="Amount collected" error={errors.amount_collected}>
                        <input type="number" step="0.01" min="0.01" value={data.amount_collected} onChange={(e) => setData('amount_collected', e.target.value)} className={inputClass(errors.amount_collected)} />
                    </Field>
                    <Field label="Collected on">
                        <input type="date" value={data.collected_at} onChange={(e) => setData('collected_at', e.target.value)} className={inputClass()} />
                    </Field>
                </div>
                <Field label="Reference">
                    <input value={data.reference} onChange={(e) => setData('reference', e.target.value)} placeholder="Delivery note #, settlement ref…" className={inputClass()} />
                </Field>
                <Field label="Note">
                    <textarea value={data.note} onChange={(e) => setData('note', e.target.value)} rows={2} className={inputClass()} />
                </Field>
                <ModalActions onClose={onClose} processing={processing} submitLabel="Confirm collected" />
            </form>
        </Modal>
    );
}

function SettlementModal({ orders, accounts, stores, onClose, onDone }) {
    const gross = orders.reduce((sum, o) => sum + Number(o.total ?? 0), 0);
    const { data, setData, post, processing, errors } = useForm({
        store_id: '',
        carrier_name: orders[0]?.external_carrier ?? '',
        settlement_date: new Date().toISOString().slice(0, 10),
        period_start: '',
        period_end: '',
        delivery_fees: '0',
        adjustments: '0',
        account_id: accounts.find((a) => a.type === 'bank')?.id ?? '',
        reference: '',
        notes: '',
        order_ids: orders.map((o) => o.id),
    });

    const net = gross - (Number(data.delivery_fees) || 0) - (Number(data.adjustments) || 0);

    const submit = (e) => {
        e.preventDefault();
        post('/dashboard/finance/cod-settlements', { onSuccess: onDone, preserveScroll: true });
    };

    return (
        <Modal title={`Create external COD settlement — ${orders.length} order(s)`} onClose={onClose} wide>
            <form onSubmit={submit} className="space-y-4">
                <div className="rounded-lg bg-surface-3 border border-line px-3 py-2 text-sm text-content-muted">
                    Gross COD for the selected orders: <span className="font-semibold text-content tabular-nums">{money(gross)}</span>
                </div>
                <div className="grid grid-cols-2 gap-3">
                    <Field label="Carrier / delivery company">
                        <input value={data.carrier_name} onChange={(e) => setData('carrier_name', e.target.value)} placeholder="Ozon, Sendit…" className={inputClass()} />
                    </Field>
                    <Field label="Store (optional)">
                        <select value={data.store_id} onChange={(e) => setData('store_id', e.target.value)} className={inputClass()}>
                            <option value="">All stores (organization)</option>
                            {stores.map((s) => <option key={s.id} value={s.id}>{s.name}</option>)}
                        </select>
                    </Field>
                </div>
                <div className="grid grid-cols-3 gap-3">
                    <Field label="Settlement date" required error={errors.settlement_date}>
                        <input type="date" value={data.settlement_date} onChange={(e) => setData('settlement_date', e.target.value)} className={inputClass(errors.settlement_date)} />
                    </Field>
                    <Field label="Period start">
                        <input type="date" value={data.period_start} onChange={(e) => setData('period_start', e.target.value)} className={inputClass()} />
                    </Field>
                    <Field label="Period end" error={errors.period_end}>
                        <input type="date" value={data.period_end} onChange={(e) => setData('period_end', e.target.value)} className={inputClass(errors.period_end)} />
                    </Field>
                </div>
                <div className="grid grid-cols-2 gap-3">
                    <Field label="Delivery fees" error={errors.delivery_fees}>
                        <input type="number" step="0.01" min="0" value={data.delivery_fees} onChange={(e) => setData('delivery_fees', e.target.value)} className={inputClass(errors.delivery_fees)} />
                    </Field>
                    <Field label="Adjustments">
                        <input type="number" step="0.01" value={data.adjustments} onChange={(e) => setData('adjustments', e.target.value)} className={inputClass()} />
                    </Field>
                </div>
                <Field label="Received into account" required error={errors.account_id}>
                    <select value={data.account_id} onChange={(e) => setData('account_id', e.target.value)} className={inputClass(errors.account_id)}>
                        <option value="">Select an account (usually Bank)</option>
                        {accounts.map((a) => <option key={a.id} value={a.id}>{a.name}</option>)}
                    </select>
                </Field>
                <div className="rounded-lg bg-success-soft px-3 py-2 text-sm">
                    Net received: <span className="font-semibold text-success tabular-nums">{money(net)}</span>
                </div>
                <Field label="Reference">
                    <input value={data.reference} onChange={(e) => setData('reference', e.target.value)} className={inputClass()} />
                </Field>
                <Field label="Notes">
                    <textarea value={data.notes} onChange={(e) => setData('notes', e.target.value)} rows={2} className={inputClass()} />
                </Field>
                {errors.order_ids && <p className="text-xs text-danger">{errors.order_ids}</p>}
                <p className="text-xs text-content-muted">This creates a draft — nothing is posted to the ledger until you "Settle" it from the External settlements tab.</p>
                <ModalActions onClose={onClose} processing={processing} submitLabel="Create draft settlement" />
            </form>
        </Modal>
    );
}

function DepositModal({ orders, accounts, stores, couriers, onClose, onDone }) {
    const expected = orders.reduce((sum, o) => sum + Number(o.total ?? 0), 0);
    const { data, setData, post, processing, errors } = useForm({
        store_id: '',
        courier_id: couriers[0]?.id ?? '',
        deposit_date: new Date().toISOString().slice(0, 10),
        cash_received: expected.toFixed(2),
        account_id: accounts.find((a) => a.type === 'cash')?.id ?? '',
        reference: '',
        notes: '',
        order_ids: orders.map((o) => o.id),
    });

    const difference = (Number(data.cash_received) || 0) - expected;

    const submit = (e) => {
        e.preventDefault();
        post('/dashboard/finance/courier-deposits', { onSuccess: onDone, preserveScroll: true });
    };

    return (
        <Modal title={`Create courier cash deposit — ${orders.length} order(s)`} onClose={onClose} wide>
            <form onSubmit={submit} className="space-y-4">
                <div className="rounded-lg bg-surface-3 border border-line px-3 py-2 text-sm text-content-muted">
                    Expected COD for the selected orders: <span className="font-semibold text-content tabular-nums">{money(expected)}</span>
                </div>
                <div className="grid grid-cols-2 gap-3">
                    <Field label="Courier" required error={errors.courier_id}>
                        <select value={data.courier_id} onChange={(e) => setData('courier_id', e.target.value)} className={inputClass(errors.courier_id)}>
                            <option value="">Select a courier</option>
                            {couriers.map((c) => <option key={c.id} value={c.id}>{c.name}</option>)}
                        </select>
                        {couriers.length === 0 && <p className="mt-1 text-xs text-warning">No internal delivery agents found for this store.</p>}
                    </Field>
                    <Field label="Store (optional)">
                        <select value={data.store_id} onChange={(e) => setData('store_id', e.target.value)} className={inputClass()}>
                            <option value="">All stores (organization)</option>
                            {stores.map((s) => <option key={s.id} value={s.id}>{s.name}</option>)}
                        </select>
                    </Field>
                </div>
                <div className="grid grid-cols-2 gap-3">
                    <Field label="Deposit date" required error={errors.deposit_date}>
                        <input type="date" value={data.deposit_date} onChange={(e) => setData('deposit_date', e.target.value)} className={inputClass(errors.deposit_date)} />
                    </Field>
                    <Field label="Cash received" error={errors.cash_received}>
                        <input type="number" step="0.01" min="0" value={data.cash_received} onChange={(e) => setData('cash_received', e.target.value)} className={inputClass(errors.cash_received)} />
                    </Field>
                </div>
                <Field label="Deposited into account" required error={errors.account_id}>
                    <select value={data.account_id} onChange={(e) => setData('account_id', e.target.value)} className={inputClass(errors.account_id)}>
                        <option value="">Select an account (usually Cash)</option>
                        {accounts.map((a) => <option key={a.id} value={a.id}>{a.name}</option>)}
                    </select>
                </Field>
                {Math.abs(difference) > 0.001 && (
                    <div className={`rounded-lg px-3 py-2 text-sm ${difference < 0 ? 'bg-danger-soft text-danger' : 'bg-warning-soft text-warning'}`}>
                        {difference < 0 ? 'Shortage' : 'Overage'} of <span className="font-semibold tabular-nums">{money(Math.abs(difference))}</span> vs. expected — this will be recorded as a clearly labelled variance.
                    </div>
                )}
                <Field label="Reference">
                    <input value={data.reference} onChange={(e) => setData('reference', e.target.value)} className={inputClass()} />
                </Field>
                <Field label="Notes">
                    <textarea value={data.notes} onChange={(e) => setData('notes', e.target.value)} rows={2} className={inputClass()} />
                </Field>
                {errors.order_ids && <p className="text-xs text-danger">{errors.order_ids}</p>}
                <p className="text-xs text-content-muted">This creates a draft — nothing is posted to the ledger until you "Confirm" it from the Courier deposits tab.</p>
                <ModalActions onClose={onClose} processing={processing} submitLabel="Create draft deposit" />
            </form>
        </Modal>
    );
}

function PeriodCard({ period, canManage, highlighted, onVerify }) {
    const overdue = period.status === 'overdue';
    const cardRef = useRef(null);

    // "View settlement period" jumped here from the Pending tab — scroll it
    // into view once so the accountant doesn't have to hunt for it among
    // every provider's periods.
    useEffect(() => {
        if (highlighted) cardRef.current?.scrollIntoView({ behavior: 'smooth', block: 'center' });
    }, [highlighted]);

    return (
        <div ref={cardRef} className={`rounded-xl border p-4 transition ${highlighted ? 'border-primary ring-2 ring-primary/40' : overdue ? 'border-danger/30 bg-danger-soft/30' : 'border-line bg-surface-2'}`}>
            <div className="flex items-start justify-between gap-2 mb-2">
                <div>
                    <p className="text-sm font-semibold text-content flex items-center gap-1.5">
                        {period.provider_name}
                        {period.payout_frequency === 'instant' && <Zap className="w-3.5 h-3.5 text-primary" aria-label="Instant payout" />}
                    </p>
                    <p className="text-xs text-content-muted">
                        {period.payout_frequency === 'instant' ? 'Instant payout' : period.payout_frequency === 'daily' ? `Daily payout · ${formatDateOnly(period.period_start)}` : `${formatDateOnly(period.period_start)} → ${formatDateOnly(period.period_end)}`}
                    </p>
                </div>
                <span className={`inline-flex px-2 py-0.5 rounded-full text-xs font-medium ${PERIOD_STATUS_STYLE[period.status] ?? 'bg-slate-500/15 text-slate-500'}`}>
                    {PERIOD_STATUS_LABEL[period.status] ?? period.status}
                </span>
            </div>

            <div className="space-y-1 text-sm mb-3">
                <div className="flex justify-between"><span className="text-content-muted">Delivered orders</span><span className="text-content tabular-nums">{period.delivered_orders_count}</span></div>
                <div className="flex justify-between"><span className="text-content-muted">Gross COD</span><span className="text-content tabular-nums">{money(period.gross_cod)}</span></div>
                <div className="flex justify-between"><span className="text-content-muted">Expected fees</span><span className="text-danger tabular-nums">-{money(period.expected_fees)}</span></div>
                <div className="flex justify-between border-t border-line pt-1"><span className="font-medium text-content">Expected net</span><span className="font-semibold text-success tabular-nums">{money(period.expected_net)}</span></div>
            </div>

            {period.has_manual_required_fees && (
                <p className="flex items-center gap-1.5 text-xs text-warning mb-3"><AlertTriangle className="w-3.5 h-3.5 flex-shrink-0" /> Some orders have no fee configured — review Delivery Providers settings.</p>
            )}

            <p className="text-xs text-content-muted mb-3">
                Expected payout {formatDateOnly(period.payout_date)}
                {overdue ? <span className="text-danger font-medium"> · overdue by {Math.abs(period.days_until_payout)} day(s)</span> : period.days_until_payout >= 0 ? ` · in ${period.days_until_payout} day(s)` : null}
            </p>

            {canManage && <Button className="w-full justify-center" icon={Landmark} onClick={onVerify}>Verify bank transfer</Button>}
        </div>
    );
}

function ReconcileModal({ target, accounts, onClose, onDone }) {
    const isPeriod = target.mode === 'period';
    const period = isPeriod ? target.period : null;
    const settlement = ! isPeriod ? target.settlement : null;
    const expected = isPeriod ? period.expected_net : Number(settlement.expected_net_amount ?? settlement.net_received);

    const { data, setData, post, processing, errors } = useForm({
        delivery_provider_id: period?.delivery_provider_id ?? '',
        period_start: period?.period_start ?? '',
        period_end: period?.period_end ?? '',
        order_ids: period?.order_ids ?? [],
        actual_received_amount: isPeriod ? period.expected_net.toFixed(2) : expected.toFixed(2),
        account_id: (isPeriod ? period.default_bank_account_id : settlement.account_id) ?? accounts.find((a) => a.type === 'bank')?.id ?? '',
        received_at: new Date().toISOString().slice(0, 10),
        reference: '',
        notes: '',
    });

    const variance = (Number(data.actual_received_amount) || 0) - expected;

    const submit = (e) => {
        e.preventDefault();
        const url = isPeriod ? '/dashboard/finance/cod-settlements/verify-period' : `/dashboard/finance/cod-settlements/${settlement.id}/reconcile`;
        post(url, { onSuccess: onDone, preserveScroll: true });
    };

    return (
        <Modal title={isPeriod ? `Verify bank transfer — ${period.provider_name}` : 'Reconcile settlement'} onClose={onClose} wide>
            <form onSubmit={submit} className="space-y-4">
                <div className="rounded-lg bg-surface-3 border border-line px-3 py-2 text-sm text-content-muted space-y-1">
                    {isPeriod && <div className="flex justify-between"><span>Period</span><span className="text-content">{formatDateOnly(period.period_start)} → {formatDateOnly(period.period_end)}</span></div>}
                    <div className="flex justify-between"><span>Gross COD</span><span className="text-content tabular-nums">{money(isPeriod ? period.gross_cod : settlement.gross_cod_amount)}</span></div>
                    <div className="flex justify-between"><span>Expected fees</span><span className="text-content tabular-nums">{money(isPeriod ? period.expected_fees : settlement.delivery_fees)}</span></div>
                    <div className="flex justify-between font-medium"><span>Expected net</span><span className="text-content tabular-nums">{money(expected)}</span></div>
                </div>

                <div className="grid grid-cols-2 gap-3">
                    <Field label="Actual amount received" required error={errors.actual_received_amount}>
                        <input type="number" step="0.01" value={data.actual_received_amount} onChange={(e) => setData('actual_received_amount', e.target.value)} className={inputClass(errors.actual_received_amount)} />
                    </Field>
                    <Field label="Received into account" required error={errors.account_id}>
                        <select value={data.account_id} onChange={(e) => setData('account_id', e.target.value)} className={inputClass(errors.account_id)}>
                            <option value="">Select an account (usually Bank)</option>
                            {accounts.map((a) => <option key={a.id} value={a.id}>{a.name}</option>)}
                        </select>
                    </Field>
                </div>

                <div className="grid grid-cols-2 gap-3">
                    <Field label="Received on">
                        <input type="date" value={data.received_at} onChange={(e) => setData('received_at', e.target.value)} className={inputClass()} />
                    </Field>
                    <Field label="Bank reference">
                        <input value={data.reference} onChange={(e) => setData('reference', e.target.value)} className={inputClass()} />
                    </Field>
                </div>

                {Math.abs(variance) > 0.01 && (
                    <div className={`rounded-lg px-3 py-2 text-sm ${variance < 0 ? 'bg-danger-soft text-danger' : 'bg-warning-soft text-warning'}`}>
                        Variance of <span className="font-semibold tabular-nums">{variance > 0 ? '+' : ''}{money(variance)}</span> vs. expected — this will be recorded as {variance < 0 ? 'partial' : 'disputed'}.
                    </div>
                )}

                <Field label="Notes" required={Math.abs(variance) > 0.01} error={errors.notes}>
                    <textarea value={data.notes} onChange={(e) => setData('notes', e.target.value)} rows={2} placeholder={Math.abs(variance) > 0.01 ? 'Explain the variance…' : 'Optional'} className={inputClass(errors.notes)} />
                </Field>

                {errors.order_ids && <p className="text-xs text-danger">{errors.order_ids}</p>}
                <ModalActions onClose={onClose} processing={processing} submitLabel="Verify & record" />
            </form>
        </Modal>
    );
}

/**
 * Shown instead of silently switching to an empty-looking External
 * settlements tab — the backend (FinanceCodSettlementDiagnosticsService)
 * already worked out exactly why this order isn't in a payout period yet.
 * The "Recalculate" action only ever appears when the backend says it's
 * actually available (local/testing + owner/admin — see can.recalculate_settlement);
 * it never creates cash or closes the receivable, only repairs Shipment/fee
 * data so the real period computation can find the order.
 */
function SettlementDiagnosticsModal({ order, canRecalculate, onClose }) {
    const { post, processing } = useForm({});

    const recalculate = () => {
        post(`/dashboard/finance/cod-receivables/${order.id}/recalculate-settlement`, {
            preserveScroll: true,
            onSuccess: onClose,
        });
    };

    return (
        <Modal title={`Not in a payout period yet — ${order.order_number}`} onClose={onClose}>
            <div className="space-y-4">
                <p className="text-sm text-content-muted">This order shows as awaiting an external provider payout, but isn't in a live period yet:</p>
                <ul className="space-y-1.5">
                    {(order.settlement_diagnostics ?? []).map((reason, i) => (
                        <li key={i} className="flex items-start gap-2 text-sm text-content">
                            <AlertTriangle className="w-3.5 h-3.5 flex-shrink-0 mt-0.5 text-warning" />
                            {reason}
                        </li>
                    ))}
                </ul>

                {canRecalculate && (
                    <div className="rounded-lg border border-line bg-surface-3 px-3 py-2.5">
                        <p className="text-xs text-content-muted mb-2">Local/dev tool — repairs the Shipment record and recomputes its fee snapshot only. Never creates cash transactions or closes the receivable.</p>
                        <Button variant="secondary" loading={processing} onClick={recalculate}>Recalculate settlement data</Button>
                    </div>
                )}

                <div className="flex justify-end pt-2 border-t border-line">
                    <Button type="button" variant="secondary" onClick={onClose}>Close</Button>
                </div>
            </div>
        </Modal>
    );
}

function inputClass(error) {
    return `w-full px-3 py-2 rounded-lg bg-surface-3 border ${error ? 'border-danger' : 'border-line'} text-content focus:outline-none focus:ring-2 focus:ring-primary`;
}

function Field({ label, required, error, children }) {
    return (
        <div>
            <label className="block text-sm font-medium text-content-muted mb-1">{label} {required && <span className="text-danger">*</span>}</label>
            {children}
            {error && <p className="mt-1 text-xs text-danger">{error}</p>}
        </div>
    );
}

function Modal({ title, onClose, wide, children }) {
    return (
        <div className="fixed inset-0 z-50 flex items-center justify-center bg-black/40 px-4 overflow-y-auto py-8">
            <div className={`w-full ${wide ? 'max-w-xl' : 'max-w-md'} rounded-xl bg-surface-2 border border-line p-6 shadow-xl`}>
                <div className="flex items-center justify-between mb-4">
                    <h3 className="text-base font-semibold text-content">{title}</h3>
                    <button type="button" onClick={onClose} className="text-content-muted hover:text-content"><X className="w-4 h-4" /></button>
                </div>
                {children}
            </div>
        </div>
    );
}

function ModalActions({ onClose, processing, submitLabel }) {
    return (
        <div className="flex justify-end gap-2 pt-2">
            <Button type="button" variant="secondary" onClick={onClose}>Cancel</Button>
            <Button type="submit" loading={processing}>{submitLabel}</Button>
        </div>
    );
}
