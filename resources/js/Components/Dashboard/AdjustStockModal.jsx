import { useEffect, useMemo, useRef, useState } from 'react';
import axios from 'axios';
import {
    X, Loader2, Layers, Search, Pencil, PackagePlus, Undo2, PackageX, Warehouse,
    CheckCircle2, Clock, ArrowRight, ChevronDown, ChevronRight, Bug,
} from 'lucide-react';

// Each tab is a distinct inventory workflow. `mode` maps to the backend contract
// (delta = signed change, set = absolute target); `type` is the stock_ledger
// category the movement is filed under; `op` decides how a row's input becomes a
// stock change. Every tab carries its own reason list — the picked label is
// prepended to the free-text notes so history reads e.g. "Customer return — ...".
const TABS = [
    {
        key:   'set',
        label: 'Set stock',
        icon:  Pencil,
        mode:  'set',
        type:  'adjustment',
        op:    'set',
        accent: 'indigo',
        hint:  'Sets the physical on-hand quantity. Waiting orders may reserve stock automatically after save.',
        reasons: [
            { value: 'Stock recount',       label: 'Stock recount' },
            { value: 'Correction',          label: 'Correction' },
            { value: 'Opening balance',     label: 'Opening balance' },
            { value: 'Cycle count',         label: 'Cycle count' },
        ],
    },
    {
        key:   'restock',
        label: 'Restock',
        icon:  PackagePlus,
        mode:  'delta',
        type:  'adjustment',
        op:    'delta',
        accent: 'emerald',
        hint:  'Adds received stock to on-hand quantity.',
        reasons: [
            { value: 'Purchase order (PO)', label: 'Restock — Purchase order' },
            { value: 'New arrivals',        label: 'New arrivals' },
            { value: 'Supplier delivery',   label: 'Supplier delivery' },
            { value: 'Manual correction',   label: 'Manual correction' },
        ],
    },
    {
        key:   'return',
        label: 'Returns',
        icon:  Undo2,
        mode:  'delta',
        type:  'return',
        op:    'add',
        accent: 'amber',
        hint:  'Adds resellable returned stock to sellable inventory.',
        reasons: [
            { value: 'Customer return',     label: 'Customer return' },
            { value: 'Wrong item shipped',  label: 'Wrong item shipped' },
            { value: 'Changed mind',        label: 'Changed mind' },
            { value: 'Resellable defect',   label: 'Resellable defect' },
        ],
    },
    {
        key:   'damage',
        label: 'Damaged',
        icon:  PackageX,
        mode:  'delta',
        type:  'damage',
        op:    'remove',
        accent: 'rose',
        hint:  'Moves stock to damaged/non-sellable inventory. It will not increase available stock.',
        reasons: [
            { value: 'Warehouse damage',    label: 'Warehouse damage' },
            { value: 'Expired / spoiled',   label: 'Expired / spoiled' },
            { value: 'Lost or theft',       label: 'Lost or theft' },
            { value: 'Defective write-off', label: 'Defective write-off' },
        ],
    },
];

// Each tab's accent is routed through the shared semantic tokens rather than
// a raw Tailwind hue: "Set stock" tracks the brand primary, the other three
// map to the fixed success/warning/danger states (restock = positive,
// returns = needs attention, damaged = negative) — same vocabulary as
// StatusBadge's stock/sync maps. `text` is the button's own label color:
// primary is store-customizable so it needs the contrast-safe token, while
// success/warning/danger are fixed-safe hues where white always contrasts.
const ACCENTS = {
    indigo:  { tabText: 'text-primary',  tabBar: 'bg-primary',  chipBg: 'bg-primary-soft',  rowActive: 'bg-primary-soft',  btn: 'bg-primary hover:bg-primary-strong',    text: 'text-primary-contrast' },
    emerald: { tabText: 'text-success',  tabBar: 'bg-success',  chipBg: 'bg-success-soft',  rowActive: 'bg-success-soft',  btn: 'bg-success hover:brightness-90',        text: 'text-white' },
    amber:   { tabText: 'text-warning',  tabBar: 'bg-warning',  chipBg: 'bg-warning-soft',  rowActive: 'bg-warning-soft',  btn: 'bg-warning hover:brightness-90',        text: 'text-white' },
    rose:    { tabText: 'text-danger',   tabBar: 'bg-danger',   chipBg: 'bg-danger-soft',   rowActive: 'bg-danger-soft',   btn: 'bg-danger hover:brightness-90',         text: 'text-white' },
};

// Turn a raw input string into a signed stock change for the given operation.
// Returns null when the input is empty/invalid (row is skipped).
function effectiveChange(op, current, raw) {
    if (raw === '' || raw == null) return null;
    const n = parseInt(raw, 10);
    if (Number.isNaN(n)) return null;

    switch (op) {
        case 'set':    return n < 0 ? null : n - current; // absolute target → delta
        case 'delta':  return n;                          // signed
        case 'add':    return n > 0 ? n : 0;              // returns only add
        case 'remove': return n > 0 ? -n : 0;             // damage only removes
        default:       return null;
    }
}

// Current level for a line AT the target warehouse, read from its per-warehouse
// breakdown. Falls back to the effective headline stock when no breakdown/warehouse
// is supplied (keeps the modal working if opened without a warehouse context).
function currentAt(breakdown, warehouseId, fallback) {
    if (! warehouseId || ! Array.isArray(breakdown)) return Number(fallback ?? 0);
    return Number(breakdown.find((w) => w.warehouse_id === warehouseId)?.quantity ?? 0);
}

export default function AdjustStockModal({ product, warehouse = null, onClose, onSuccess }) {
    const isVariable  = !! product.has_variants;
    const variants    = product.variants ?? [];
    const whId        = warehouse?.id ?? null;
    const baseCurrent = currentAt(product.breakdown, whId, product.total_stock ?? product.stock ?? 0);

    const [tabKey, setTabKey] = useState('set');
    // Per-tab input maps keyed by row ('base' for a simple product, else variant id)
    // so switching tabs never loses what you typed in another.
    const [values, setValues] = useState({ set: {}, restock: {}, return: {}, damage: {} });
    const [reasons, setReasons] = useState(() =>
        Object.fromEntries(TABS.map((t) => [t.key, t.reasons[0].value])),
    );
    const [notes, setNotes]           = useState('');
    const [filter, setFilter]         = useState('');
    const [submitting, setSubmitting] = useState(false);
    const [error, setError]           = useState(null);
    // Server-computed operational picture (on_hand/reserved/available/waiting)
    // for the TARGET warehouse specifically, keyed by row. The list page's
    // product/variant props only carry a warehouse-scope aggregate (which is
    // "every sellable warehouse" when no filter is active) — this fetches the
    // exact numbers for the one warehouse the adjustment will actually land
    // in, via the same read-only preview endpoint used for the "expected
    // after save" figures below.
    const [snapshots, setSnapshots] = useState({});
    // Debounced "expected after save" preview per row, from the same endpoint.
    const [previews, setPreviews]   = useState({});
    const [result, setResult]       = useState(null); // set on successful save — switches the modal into the result view
    const [showDebug, setShowDebug] = useState(false);
    const previewTimers = useRef({});

    const tab    = TABS.find((t) => t.key === tabKey);
    const accent = ACCENTS[tab.accent];

    useEffect(() => {
        const onEsc = (e) => { if (e.key === 'Escape') onClose(); };
        window.addEventListener('keydown', onEsc);
        return () => window.removeEventListener('keydown', onEsc);
    }, [onClose]);

    // Every stock line for this product, with its current level at the target
    // warehouse (so edits match the row the backend will write). `on_hand` /
    // `reserved` / `available` / `waiting_demand` start from the list page's
    // aggregate as an immediate best-guess, then get overwritten per row once
    // its own snapshot for THIS warehouse loads (see the effect below).
    const allRows = useMemo(() => (
        isVariable
            ? variants.map((v) => ({
                key: v.id, variant_id: v.id, name: v.name, sku: v.sku,
                current: currentAt(v.breakdown, whId, v.stock),
                on_hand: v.on_hand ?? 0, reserved: v.reserved ?? 0, available: v.available ?? 0, waiting_demand: v.waiting_demand ?? 0,
                inventory_item_id: v.inventory_item_id ?? null,
            }))
            : [{
                key: 'base', variant_id: null, name: product.name, sku: product.sku, current: baseCurrent,
                on_hand: product.on_hand ?? 0, reserved: product.reserved ?? 0, available: product.available ?? 0, waiting_demand: product.waiting_demand ?? 0,
                inventory_item_id: product.inventory_item_id ?? null,
            }]
    ), [isVariable, variants, product.name, product.sku, product.on_hand, product.reserved, product.available, product.waiting_demand, product.inventory_item_id, baseCurrent, whId]);

    // Fetch the exact per-target-warehouse snapshot for every row once,
    // whenever the product or target warehouse changes. Read-only — quantity:0
    // in delta mode is a no-op adjustment, so expected_* just mirrors current_*.
    useEffect(() => {
        if (! whId) return;
        let cancelled = false;

        Promise.all(allRows.map((r) => axios.post(`/dashboard/stock/${product.id}/preview-adjustment`, {
            warehouse_id: whId, variant_id: r.variant_id, mode: 'delta', quantity: 0,
        }).then((res) => [r.key, res.data]).catch(() => [r.key, null])))
            .then((entries) => {
                if (cancelled) return;
                setSnapshots(Object.fromEntries(entries.filter(([, v]) => v !== null)));
            });

        return () => { cancelled = true; };
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [product.id, whId]);

    // Debounced "expected after save" preview for one row — called from StockRow.
    const requestPreview = (row, mode, quantity) => {
        clearTimeout(previewTimers.current[row.key]);
        previewTimers.current[row.key] = setTimeout(() => {
            axios.post(`/dashboard/stock/${product.id}/preview-adjustment`, {
                warehouse_id: whId, variant_id: row.variant_id, mode, quantity,
            }).then((res) => {
                setPreviews((prev) => ({ ...prev, [row.key]: res.data }));
            }).catch(() => { /* best-effort — the naive client-side estimate still shows */ });
        }, 350);
    };

    // Snapshot-enriched rows: once the per-warehouse fetch resolves for a row,
    // its on_hand/reserved/available/waiting_demand are the exact numbers for
    // THIS warehouse rather than the list page's cross-warehouse aggregate.
    const enrichedRows = useMemo(() => allRows.map((r) => {
        const snap = snapshots[r.key];
        return snap ? {
            ...r,
            on_hand: snap.current_on_hand, reserved: snap.current_reserved, available: snap.current_available,
            waiting_demand: snap.waiting_demand, affected_waiting_orders_count: snap.affected_waiting_orders_count,
        } : r;
    }), [allRows, snapshots]);

    const displayRows = useMemo(() => {
        const q = filter.trim().toLowerCase();
        if (! isVariable || ! q) return enrichedRows;
        return enrichedRows.filter((r) => r.name.toLowerCase().includes(q) || (r.sku ?? '').toLowerCase().includes(q));
    }, [enrichedRows, isVariable, filter]);

    // Rows the active tab will actually submit (skip empty / no-op inputs).
    const pendingRows = useMemo(() => (
        allRows
            .map((r) => ({ ...r, change: effectiveChange(tab.op, r.current, values[tabKey][r.key]) }))
            .filter((r) => r.change !== null && r.change !== 0)
    ), [allRows, tab.op, values, tabKey]);

    const setRow = (rowKey, value) =>
        setValues((prev) => ({ ...prev, [tabKey]: { ...prev[tabKey], [rowKey]: value } }));

    const submit = async (e) => {
        e.preventDefault();

        if (pendingRows.length === 0) {
            setError('Enter a quantity for at least one item.');
            return;
        }

        const reasonLabel = tab.reasons.find((r) => r.value === reasons[tabKey])?.value ?? '';
        const composedNotes = [reasonLabel, notes.trim()].filter(Boolean).join(' — ') || null;

        const adjustments = pendingRows.map((r) => (
            tab.mode === 'set'
                ? { variant_id: r.variant_id, quantity: r.current + r.change }
                : { variant_id: r.variant_id, quantity_change: r.change }
        ));

        setError(null);
        setSubmitting(true);

        try {
            const res = await axios.post(`/dashboard/stock/${product.id}/adjust`, {
                mode:         tab.mode,
                reason:       tab.type,
                notes:        composedNotes,
                warehouse_id: whId,
                adjustments,
            }, { headers: { 'X-Requested-With': 'XMLHttpRequest' } });

            // Refresh the underlying page data now (so it's current the
            // moment the user dismisses the result), but keep the modal open
            // to actually SHOW what happened — never close silently.
            onSuccess?.();
            setResult(res.data);
        } catch (err) {
            const msg = err?.response?.data?.message
                ?? (err?.response?.data?.errors
                    ? Object.values(err.response.data.errors).flat().join(' ')
                    : null)
                ?? 'Failed to update stock.';
            setError(msg);
        } finally {
            setSubmitting(false);
        }
    };

    return (
        <div
            className="fixed inset-0 z-50 flex items-center justify-center bg-black/60 p-4"
            onClick={(e) => { if (e.target === e.currentTarget) onClose(); }}
        >
            <div className={`bg-surface-2 border border-line rounded-[var(--radius-card)] shadow-2xl w-full ${isVariable ? 'max-w-lg' : 'max-w-md'} max-h-[90vh] flex flex-col`}>
                <header className="flex items-center justify-between border-b border-line px-5 py-4">
                    <div className="min-w-0">
                        <h3 className="font-semibold text-content truncate flex items-center gap-2">
                            Adjust stock
                            {isVariable && (
                                <span className="inline-flex items-center gap-1 px-1.5 py-0.5 rounded-full bg-primary-soft text-primary-strong dark:text-primary text-[10px] font-medium">
                                    <Layers className="w-2.5 h-2.5" />
                                    {product.variant_count} variants
                                </span>
                            )}
                        </h3>
                        <p className="text-xs text-content-muted truncate">
                            {product.name} <span className="font-mono">· {product.sku}</span>
                        </p>
                        {warehouse?.name && (
                            <p className="mt-1 inline-flex items-center gap-1 px-1.5 py-0.5 rounded-md bg-surface-3 text-content-muted text-[11px]">
                                <Warehouse className="w-3 h-3" />
                                {warehouse.name}
                            </p>
                        )}
                    </div>
                    <button
                        type="button"
                        onClick={onClose}
                        aria-label="Close"
                        className="p-1 text-content-muted hover:text-content"
                    >
                        <X className="w-4 h-4" />
                    </button>
                </header>

                {result ? (
                    <AdjustmentResultView result={result} onDone={onClose} />
                ) : (
                <>
                {/* Tab navigation */}
                <nav className="flex-shrink-0 border-b border-line px-2 overflow-x-auto" role="tablist" aria-label="Adjustment mode">
                    <div className="flex min-w-max">
                        {TABS.map((t) => {
                            const Icon   = t.icon;
                            const active = t.key === tabKey;
                            const a      = ACCENTS[t.accent];
                            return (
                                <button
                                    key={t.key}
                                    type="button"
                                    role="tab"
                                    aria-selected={active}
                                    onClick={() => { setTabKey(t.key); setError(null); }}
                                    className={`relative flex items-center gap-1.5 px-3 py-2.5 text-xs font-medium whitespace-nowrap transition ${
                                        active ? a.tabText : 'text-content-muted hover:text-content'
                                    }`}
                                >
                                    <Icon className="w-3.5 h-3.5" />
                                    {t.label}
                                    {active && <span className={`absolute inset-x-2 -bottom-px h-0.5 rounded-full ${a.tabBar}`} />}
                                </button>
                            );
                        })}
                    </div>
                </nav>

                <form onSubmit={submit} className="flex-1 min-h-0 flex flex-col">
                    <div className="flex-1 min-h-0 overflow-y-auto px-5 py-4 space-y-4">
                        <p className="text-xs text-content-muted">{tab.hint}</p>

                        {error && (
                            <div className="rounded-md bg-danger-soft border border-danger/30 text-danger text-xs px-3 py-2">
                                {error}
                            </div>
                        )}

                        {/* Reason picker — specific to the active tab */}
                        <label className="block">
                            <span className="text-xs text-content-muted font-medium">Reason</span>
                            <select
                                value={reasons[tabKey]}
                                onChange={(e) => setReasons((prev) => ({ ...prev, [tabKey]: e.target.value }))}
                                className="mt-1 w-full px-3 py-2 rounded-[var(--radius-button)] border border-line bg-surface text-content text-sm focus:outline-none focus:ring-2 focus:ring-primary"
                            >
                                {tab.reasons.map((r) => (
                                    <option key={r.value} value={r.value}>{r.label}</option>
                                ))}
                            </select>
                        </label>

                        {/* Rows */}
                        <div>
                            <div className="flex items-center justify-between mb-2">
                                <span className="text-xs text-content-muted font-medium">{isVariable ? 'Variants' : 'Stock'}</span>
                                <button
                                    type="button"
                                    onClick={() => setShowDebug((d) => ! d)}
                                    className="inline-flex items-center gap-1 text-[10px] text-content-muted hover:text-content transition"
                                    title="Show inventory item ids (admin)"
                                >
                                    <Bug className="w-3 h-3" /> {showDebug ? 'Hide' : 'Show'} details
                                </button>
                            </div>

                            {isVariable && allRows.length > 6 && (
                                <div className="relative mb-2">
                                    <Search className="w-3.5 h-3.5 absolute left-2.5 top-1/2 -translate-y-1/2 text-content-muted pointer-events-none" />
                                    <input
                                        type="text"
                                        value={filter}
                                        onChange={(e) => setFilter(e.target.value)}
                                        placeholder="Filter variants by name or SKU…"
                                        className="w-full pl-8 pr-3 py-1.5 text-xs rounded-[var(--radius-button)] border border-line bg-surface text-content focus:outline-none focus:ring-2 focus:ring-primary"
                                    />
                                </div>
                            )}

                            <div className="rounded-[var(--radius-button)] border border-line divide-y divide-line/60 max-h-64 overflow-y-auto">
                                {displayRows.length === 0 ? (
                                    <p className="px-3 py-6 text-center text-xs text-content-muted">No variants match your filter.</p>
                                ) : (
                                    displayRows.map((r) => (
                                        <StockRow
                                            key={r.key}
                                            row={r}
                                            op={tab.op}
                                            mode={tab.mode}
                                            accent={accent}
                                            value={values[tabKey][r.key] ?? ''}
                                            onChange={(v) => { setRow(r.key, v); }}
                                            preview={previews[r.key]}
                                            onPreview={(mode, quantity) => requestPreview(r, mode, quantity)}
                                            showDebug={showDebug}
                                        />
                                    ))
                                )}
                            </div>
                        </div>

                        <label className="block">
                            <span className="text-xs text-content-muted font-medium">Notes <span className="text-content-muted/60">(optional)</span></span>
                            <textarea
                                rows={2}
                                value={notes}
                                onChange={(e) => setNotes(e.target.value)}
                                placeholder="Extra context for this adjustment"
                                className="mt-1 w-full px-3 py-2 rounded-[var(--radius-button)] border border-line bg-surface text-content text-sm focus:outline-none focus:ring-2 focus:ring-primary"
                            />
                        </label>
                    </div>

                    <div className="flex-shrink-0 border-t border-line px-5 py-4 space-y-3">
                        <p className="text-[11px] text-content-muted">
                            {pendingRows.length === 0
                                ? (isVariable ? 'Enter a quantity next to any variant to update it.' : 'Enter a quantity to update stock.')
                                : `${pendingRows.length} ${pendingRows.length > 1 ? 'items' : 'item'} will be updated.`}
                        </p>
                        <div className="grid grid-cols-2 gap-2">
                            <button
                                type="button"
                                onClick={onClose}
                                disabled={submitting}
                                className="px-3 py-2 rounded-[var(--radius-button)] bg-content/10 text-content text-sm font-medium hover:bg-content/20 disabled:opacity-50 transition"
                            >
                                Cancel
                            </button>
                            <button
                                type="submit"
                                disabled={submitting || pendingRows.length === 0}
                                className={`px-3 py-2 rounded-[var(--radius-button)] text-sm font-semibold disabled:opacity-50 disabled:cursor-not-allowed transition flex items-center justify-center gap-2 ${accent.btn} ${accent.text}`}
                            >
                                {submitting ? <><Loader2 className="w-4 h-4 animate-spin" />Saving…</> : 'Save adjustment'}
                            </button>
                        </div>
                    </div>
                </form>
                </>
                )}
            </div>
        </div>
    );
}

// The "what happened" panel shown after a successful save — replaces the
// form rather than the modal just closing, so the operational outcome is
// always visible: stock updated, waiting orders released/still short,
// units reserved, external sync status, and quick links to go verify it.
function AdjustmentResultView({ result, onDone }) {
    const released = result.waiting_orders_released ?? 0;
    const reserved = result.waiting_units_reserved ?? 0;

    return (
        <div className="flex-1 min-h-0 overflow-y-auto px-5 py-5 space-y-4">
            <div className="flex items-start gap-3">
                <div className="flex-shrink-0 w-9 h-9 rounded-full bg-success-soft flex items-center justify-center">
                    <CheckCircle2 className="w-5 h-5 text-success" />
                </div>
                <div className="min-w-0">
                    <p className="text-sm font-semibold text-content">Stock updated</p>
                    <p className="text-xs text-content-muted mt-0.5">{result.message}</p>
                </div>
            </div>

            {result.results?.length > 0 && (
                <div className="rounded-[var(--radius-button)] border border-line divide-y divide-line/60">
                    {result.results.map((r) => (
                        <div key={r.variant_id ?? 'base'} className="flex items-center justify-between px-3 py-2 text-xs">
                            <span className="text-content-muted">{r.variant_id ? 'Variant' : 'Product'}</span>
                            <span className="tabular-nums text-content">
                                On hand <b>{r.on_hand}</b> · Reserved <b>{r.reserved}</b> · Available <b className="text-success">{r.available}</b>
                            </span>
                        </div>
                    ))}
                </div>
            )}

            <div className="grid grid-cols-2 gap-2">
                <StatChip icon={CheckCircle2} tone={released > 0 ? 'success' : 'muted'} label="Waiting orders released" value={released} />
                <StatChip icon={Clock} tone={reserved > 0 ? 'primary' : 'muted'} label="Units reserved for orders" value={reserved} />
            </div>

            {result.external_sync && result.external_sync !== 'skipped' && (
                <p className={`text-[11px] px-3 py-2 rounded-md ${
                    result.external_sync === 'queued' ? 'bg-success-soft text-success'
                        : result.external_sync === 'failed' ? 'bg-danger-soft text-danger'
                            : 'bg-warning-soft text-warning'
                }`}>
                    External stock sync {result.external_sync === 'queued' ? 'queued' : result.external_sync === 'failed' ? 'failed' : 'partially failed'}.
                </p>
            )}

            {released > 0 && (
                <div className="flex gap-2">
                    <a href={result.links?.pick_and_pack ?? '/dashboard/departments/packing'} className="flex-1 inline-flex items-center justify-center gap-1.5 px-3 py-2 text-xs font-medium rounded-[var(--radius-button)] bg-surface-3 border border-line text-content hover:bg-content/10 transition">
                        View Pick &amp; Pack <ArrowRight className="w-3 h-3" />
                    </a>
                    <a href={result.links?.waiting_stock ?? '/dashboard/operations/waiting-stock'} className="flex-1 inline-flex items-center justify-center gap-1.5 px-3 py-2 text-xs font-medium rounded-[var(--radius-button)] bg-surface-3 border border-line text-content hover:bg-content/10 transition">
                        View Waiting Stock <ArrowRight className="w-3 h-3" />
                    </a>
                </div>
            )}

            <button
                type="button"
                onClick={onDone}
                className="w-full px-3 py-2 rounded-[var(--radius-button)] bg-primary text-primary-contrast text-sm font-semibold hover:bg-primary-strong transition"
            >
                Done
            </button>
        </div>
    );
}

function StatChip({ icon: Icon, tone, label, value }) {
    const tones = {
        success: 'bg-success-soft text-success',
        primary: 'bg-primary-soft text-primary',
        muted:   'bg-surface-3 text-content-muted',
    };
    return (
        <div className={`rounded-[var(--radius-button)] px-3 py-2 ${tones[tone]}`}>
            <div className="flex items-center gap-1.5 text-[10px] uppercase tracking-wider opacity-80">
                <Icon className="w-3 h-3" /> {label}
            </div>
            <div className="text-lg font-bold tabular-nums mt-0.5">{value}</div>
        </div>
    );
}

// A single stock line: name / sku · on hand · reserved · available · waiting
// demand · input · expected-after-save (naive client estimate immediately,
// upgraded to the exact server-computed preview once it resolves).
function StockRow({ row, op, mode, accent, value, onChange, preview: serverPreview, onPreview, showDebug }) {
    const change  = effectiveChange(op, row.current, value);
    const has     = change !== null && change !== 0;
    const preview = has ? row.current + change : null;

    useEffect(() => {
        if (! has) return;
        const quantity = mode === 'set' ? row.current + change : change;
        onPreview?.(mode, quantity);
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [has, change, mode]);

    const placeholder = op === 'set' ? String(row.current) : op === 'delta' ? '±0' : '0';
    const min         = op === 'delta' ? undefined : 0;

    let previewClass = 'text-content-muted/40';
    if (preview !== null) {
        previewClass = preview < 0
            ? 'text-danger font-semibold'
            : change > 0
                ? 'text-success font-semibold'
                : 'text-warning font-semibold';
    }

    // Expected-after-save summary: the exact server preview once it resolves,
    // else a naive client estimate (on_hand only — reserved/available can't
    // be estimated client-side since releasing waiting stock is a backend
    // FIFO decision, hence the preview endpoint).
    const expected = has
        ? (serverPreview ?? {
            expected_on_hand: preview,
            expected_reserved: row.reserved,
            expected_available: Math.max(0, (preview ?? row.on_hand) - row.reserved),
        })
        : null;
    const releasable = serverPreview?.releasable_waiting_units ?? 0;

    return (
        <div className={`px-3 py-2.5 ${has ? accent.rowActive : ''}`}>
            <div className="flex items-center gap-3">
                <div className="min-w-0 flex-1">
                    <div className="text-sm font-medium text-content truncate">{row.name}</div>
                    {row.sku && <div className="text-[11px] text-content-muted font-mono truncate">{row.sku}</div>}
                    <div className="text-[11px] text-content-muted tabular-nums mt-0.5">
                        On hand <b className="text-content">{row.on_hand}</b>
                        {' · '}Reserved <b className="text-content">{row.reserved}</b>
                        {' · '}Available <b className="text-content">{row.available}</b>
                        {row.waiting_demand > 0 && (
                            <span className="ml-1.5 inline-flex items-center px-1.5 py-0.5 rounded-full bg-warning-soft text-warning font-medium">
                                Waiting {row.waiting_demand}
                            </span>
                        )}
                    </div>
                    {showDebug && (
                        <div className="text-[10px] text-content-muted/70 font-mono mt-0.5 truncate">
                            item: {row.inventory_item_id ?? '—'}
                        </div>
                    )}
                </div>

                <input
                    type="number"
                    step="1"
                    min={min}
                    value={value}
                    onChange={(e) => onChange(e.target.value)}
                    placeholder={placeholder}
                    aria-label={`${op === 'set' ? 'New level' : 'Quantity'} for ${row.name}`}
                    className="flex-shrink-0 w-20 px-2 py-1.5 text-sm text-center rounded-[var(--radius-button)] border border-line bg-surface text-content tabular-nums focus:outline-none focus:ring-2 focus:ring-primary"
                />

                <div className="flex-shrink-0 w-10 text-right text-xs tabular-nums">
                    {preview !== null ? <span className={previewClass}>{preview}</span> : <span className="text-content-muted/40">—</span>}
                </div>
            </div>

            {expected && (
                <div className="mt-1.5 pl-0 text-[11px] text-content-muted tabular-nums">
                    Expected after save: On hand <b className="text-content">{expected.expected_on_hand}</b>
                    {', '}Reserved <b className="text-content">{expected.expected_reserved}</b>
                    {releasable > 0 && <span className="text-success"> (+{releasable} released)</span>}
                    {', '}Available <b className="text-success">{expected.expected_available}</b>
                </div>
            )}
        </div>
    );
}
