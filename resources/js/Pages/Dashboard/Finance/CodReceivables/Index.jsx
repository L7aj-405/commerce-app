import { useMemo, useState } from 'react';
import { useForm, usePage } from '@inertiajs/react';
import { HandCoins, CheckCircle2, X, Truck, Users, ListChecks } from 'lucide-react';
import SaasLayout from '@/Layouts/SaasLayout';
import DataTable from '@/Components/DataTable';
import Button from '@/Components/Button';
import { formatDateTime, formatDateOnly } from '@/Support/formatDate';

function money(amount, currency = 'MAD') {
    return `${Number(amount ?? 0).toLocaleString(undefined, { minimumFractionDigits: 2, maximumFractionDigits: 2 })} ${currency}`;
}

const STATUS_STYLE = {
    draft: 'bg-slate-500/15 text-slate-600 dark:text-slate-300',
    settled: 'bg-success-soft text-success',
    confirmed: 'bg-success-soft text-success',
    cancelled: 'bg-danger-soft text-danger',
};

function StatusChip({ status }) {
    return <span className={`inline-flex px-2 py-0.5 rounded-full text-xs font-medium capitalize ${STATUS_STYLE[status] ?? 'bg-slate-500/15 text-slate-500'}`}>{status}</span>;
}

// Mirrors App\Enums\FinanceCodCollectabilityStatus — a COD receivable can
// exist (and show up here) well before it's actually collectable; this is
// what tells the accountant WHY an action is disabled.
const COLLECTABILITY_STYLE = {
    not_delivered: 'bg-slate-500/15 text-slate-600 dark:text-slate-300',
    with_internal_courier: 'bg-indigo-500/15 text-indigo-700 dark:text-indigo-300',
    with_external_carrier: 'bg-blue-500/15 text-blue-700 dark:text-blue-300',
    delivered_collectable: 'bg-success-soft text-success',
    settled: 'bg-slate-500/15 text-slate-500',
    cancelled_or_returned: 'bg-danger-soft text-danger',
};

const COLLECTABILITY_LABEL = {
    not_delivered: 'Not delivered yet',
    with_internal_courier: 'With courier',
    with_external_carrier: 'With carrier',
    delivered_collectable: 'Delivered — ready to collect',
    settled: 'Settled',
    cancelled_or_returned: 'Cancelled / returned',
};

function CollectabilityChip({ status, reason }) {
    return (
        <span
            title={reason}
            className={`inline-flex px-2 py-0.5 rounded-full text-xs font-medium ${COLLECTABILITY_STYLE[status] ?? 'bg-slate-500/15 text-slate-500'}`}
        >
            {COLLECTABILITY_LABEL[status] ?? status}
        </span>
    );
}

const TABS = [
    { key: 'pending', label: 'Pending COD', icon: HandCoins },
    { key: 'settlements', label: 'External settlements', icon: Truck },
    { key: 'deposits', label: 'Courier deposits', icon: Users },
];

export default function Index({ orders, settlements, deposits, accounts, stores, couriers, can }) {
    const permissions = usePage().props.auth?.permissions ?? [];
    const canCollect = permissions.includes('*') || permissions.includes('finance.mark_collected');
    const canManageSettlements = can?.manage_settlements ?? false;

    const [tab, setTab] = useState('pending');
    const [collecting, setCollecting] = useState(null); // ad-hoc single-order collect
    const [selected, setSelected] = useState(() => new Set());
    const [modal, setModal] = useState(null); // 'settlement' | 'deposit' | null

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
        { key: 'collectability', label: 'Collectability', render: (o) => <CollectabilityChip status={o.collectability_status} reason={o.reason} /> },
        { key: 'total', label: 'Amount', align: 'right', render: (o) => <span className="font-semibold tabular-nums text-content">{money(o.total, o.currency)}</span> },
        ...(canCollect ? [{ key: 'actions', label: '', align: 'right', render: (o) => (
            <button
                type="button"
                onClick={() => o.is_collectable && setCollecting(o)}
                disabled={! o.is_collectable}
                title={o.is_collectable ? undefined : o.reason}
                className="inline-flex items-center gap-1.5 px-3 py-1.5 text-xs font-semibold rounded-lg bg-success-soft text-success hover:brightness-95 disabled:opacity-40 disabled:cursor-not-allowed disabled:hover:brightness-100"
            >
                <CheckCircle2 className="w-3.5 h-3.5" /> Mark collected
            </button>
        ) }] : []),
    ];

    const settlementColumns = [
        { key: 'settlement_date', label: 'Date', render: (s) => formatDateOnly(s.settlement_date) },
        { key: 'carrier_name', label: 'Carrier', render: (s) => s.carrier_name ?? '—' },
        { key: 'store', label: 'Store', render: (s) => s.store?.name ?? 'Organization' },
        { key: 'gross_cod_amount', label: 'Gross COD', align: 'right', render: (s) => money(s.gross_cod_amount) },
        { key: 'delivery_fees', label: 'Fees', align: 'right', render: (s) => <span className="text-danger">-{money(s.delivery_fees)}</span> },
        { key: 'net_received', label: 'Net received', align: 'right', render: (s) => <span className="font-semibold text-success">{money(s.net_received)}</span> },
        { key: 'account', label: 'Account', render: (s) => s.account?.name ?? '—' },
        { key: 'status', label: 'Status', render: (s) => <StatusChip status={s.status} /> },
        ...(canManageSettlements ? [{ key: 'actions', label: '', align: 'right', render: (s) => (
            s.status === 'draft' ? (
                <div className="flex justify-end gap-2">
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
                <DataTable columns={settlementColumns} data={settlements} emptyIcon={Truck} emptyMessage="No external carrier settlements yet — select pending COD orders on the Pending tab to create one." />
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
