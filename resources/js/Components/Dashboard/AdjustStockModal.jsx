import { useEffect, useMemo, useState } from 'react';
import axios from 'axios';
import { X, Loader2, Layers, Search, Pencil, PackagePlus, Undo2, PackageX, Warehouse } from 'lucide-react';

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
        hint:  'Type the exact stock level for each item — it overwrites the current value.',
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
        hint:  'Add or subtract units relative to what is in stock now (use a minus sign to remove).',
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
        hint:  'Log items customers sent back — stock goes up and the move is recorded as a return.',
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
        hint:  'Write off damaged or lost units — stock goes down and the move is recorded as damage.',
        reasons: [
            { value: 'Warehouse damage',    label: 'Warehouse damage' },
            { value: 'Expired / spoiled',   label: 'Expired / spoiled' },
            { value: 'Lost or theft',       label: 'Lost or theft' },
            { value: 'Defective write-off', label: 'Defective write-off' },
        ],
    },
];

const ACCENTS = {
    indigo:  { tabText: 'text-indigo-700 dark:text-indigo-300',   tabBar: 'bg-indigo-600',  chipBg: 'bg-indigo-500/10',  rowActive: 'bg-indigo-500/5',  btn: 'bg-indigo-600 hover:bg-indigo-500' },
    emerald: { tabText: 'text-emerald-700 dark:text-emerald-300', tabBar: 'bg-emerald-600', chipBg: 'bg-emerald-500/10', rowActive: 'bg-emerald-500/5', btn: 'bg-emerald-600 hover:bg-emerald-500' },
    amber:   { tabText: 'text-amber-700 dark:text-amber-300',     tabBar: 'bg-amber-600',   chipBg: 'bg-amber-500/10',   rowActive: 'bg-amber-500/5',   btn: 'bg-amber-600 hover:bg-amber-500' },
    rose:    { tabText: 'text-rose-700 dark:text-rose-300',       tabBar: 'bg-rose-600',    chipBg: 'bg-rose-500/10',    rowActive: 'bg-rose-500/5',    btn: 'bg-rose-600 hover:bg-rose-500' },
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

    const tab    = TABS.find((t) => t.key === tabKey);
    const accent = ACCENTS[tab.accent];

    useEffect(() => {
        const onEsc = (e) => { if (e.key === 'Escape') onClose(); };
        window.addEventListener('keydown', onEsc);
        return () => window.removeEventListener('keydown', onEsc);
    }, [onClose]);

    // Every stock line for this product, with its current level at the target
    // warehouse (so edits match the row the backend will write).
    const allRows = useMemo(() => (
        isVariable
            ? variants.map((v) => ({
                key: v.id, variant_id: v.id, name: v.name, sku: v.sku,
                current: currentAt(v.breakdown, whId, v.stock),
            }))
            : [{ key: 'base', variant_id: null, name: product.name, sku: product.sku, current: baseCurrent }]
    ), [isVariable, variants, product.name, product.sku, baseCurrent, whId]);

    const displayRows = useMemo(() => {
        const q = filter.trim().toLowerCase();
        if (! isVariable || ! q) return allRows;
        return allRows.filter((r) => r.name.toLowerCase().includes(q) || (r.sku ?? '').toLowerCase().includes(q));
    }, [allRows, isVariable, filter]);

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
            await axios.post(`/dashboard/stock/${product.id}/adjust`, {
                mode:         tab.mode,
                reason:       tab.type,
                notes:        composedNotes,
                warehouse_id: whId,
                adjustments,
            }, { headers: { 'X-Requested-With': 'XMLHttpRequest' } });

            onSuccess?.();
            onClose();
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
            <div className={`bg-surface-2 border border-line rounded-2xl shadow-2xl w-full ${isVariable ? 'max-w-lg' : 'max-w-md'} max-h-[90vh] flex flex-col`}>
                <header className="flex items-center justify-between border-b border-line px-5 py-4">
                    <div className="min-w-0">
                        <h3 className="font-semibold text-content truncate flex items-center gap-2">
                            Adjust stock
                            {isVariable && (
                                <span className="inline-flex items-center gap-1 px-1.5 py-0.5 rounded-full bg-indigo-500/15 text-indigo-700 dark:text-indigo-300 text-[10px] font-medium">
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
                            <div className="rounded-md bg-red-500/10 border border-red-500/30 text-red-700 dark:text-red-300 text-xs px-3 py-2">
                                {error}
                            </div>
                        )}

                        {/* Reason picker — specific to the active tab */}
                        <label className="block">
                            <span className="text-xs text-content-muted font-medium">Reason</span>
                            <select
                                value={reasons[tabKey]}
                                onChange={(e) => setReasons((prev) => ({ ...prev, [tabKey]: e.target.value }))}
                                className="mt-1 w-full px-3 py-2 rounded-lg border border-line bg-surface text-content text-sm focus:outline-none focus:ring-2 focus:ring-indigo-500"
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
                                <span className="text-[11px] text-content-muted">Current → new</span>
                            </div>

                            {isVariable && allRows.length > 6 && (
                                <div className="relative mb-2">
                                    <Search className="w-3.5 h-3.5 absolute left-2.5 top-1/2 -translate-y-1/2 text-content-muted pointer-events-none" />
                                    <input
                                        type="text"
                                        value={filter}
                                        onChange={(e) => setFilter(e.target.value)}
                                        placeholder="Filter variants by name or SKU…"
                                        className="w-full pl-8 pr-3 py-1.5 text-xs rounded-lg border border-line bg-surface text-content focus:outline-none focus:ring-2 focus:ring-indigo-500"
                                    />
                                </div>
                            )}

                            <div className="rounded-lg border border-line divide-y divide-line/60 max-h-64 overflow-y-auto">
                                {displayRows.length === 0 ? (
                                    <p className="px-3 py-6 text-center text-xs text-content-muted">No variants match your filter.</p>
                                ) : (
                                    displayRows.map((r) => (
                                        <StockRow
                                            key={r.key}
                                            row={r}
                                            op={tab.op}
                                            accent={accent}
                                            value={values[tabKey][r.key] ?? ''}
                                            onChange={(v) => setRow(r.key, v)}
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
                                className="mt-1 w-full px-3 py-2 rounded-lg border border-line bg-surface text-content text-sm focus:outline-none focus:ring-2 focus:ring-indigo-500"
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
                                className="px-3 py-2 rounded-lg bg-content/10 text-content text-sm font-medium hover:bg-content/20 disabled:opacity-50 transition"
                            >
                                Cancel
                            </button>
                            <button
                                type="submit"
                                disabled={submitting || pendingRows.length === 0}
                                className={`px-3 py-2 rounded-lg text-white text-sm font-semibold disabled:opacity-50 disabled:cursor-not-allowed transition flex items-center justify-center gap-2 ${accent.btn}`}
                            >
                                {submitting ? <><Loader2 className="w-4 h-4 animate-spin" />Saving…</> : 'Save adjustment'}
                            </button>
                        </div>
                    </div>
                </form>
            </div>
        </div>
    );
}

// A single stock line: name / sku · current level · input · live new-level preview.
// The input's meaning (absolute vs signed vs add-only vs remove-only) follows `op`.
function StockRow({ row, op, accent, value, onChange }) {
    const change  = effectiveChange(op, row.current, value);
    const has     = change !== null && change !== 0;
    const preview = has ? row.current + change : null;

    const placeholder = op === 'set' ? String(row.current) : op === 'delta' ? '±0' : '0';
    const min         = op === 'delta' ? undefined : 0;

    let previewClass = 'text-content-muted/40';
    if (preview !== null) {
        previewClass = preview < 0
            ? 'text-red-600 dark:text-red-400 font-semibold'
            : change > 0
                ? 'text-emerald-600 dark:text-emerald-400 font-semibold'
                : 'text-amber-600 dark:text-amber-400 font-semibold';
    }

    return (
        <div className={`flex items-center gap-3 px-3 py-2.5 ${has ? accent.rowActive : ''}`}>
            <div className="min-w-0 flex-1">
                <div className="text-sm font-medium text-content truncate">{row.name}</div>
                {row.sku && <div className="text-[11px] text-content-muted font-mono truncate">{row.sku}</div>}
            </div>

            <div className="flex-shrink-0 text-right">
                <div className="text-[10px] text-content-muted uppercase tracking-wider">In stock</div>
                <div className="text-sm font-semibold tabular-nums text-content">{row.current}</div>
            </div>

            <input
                type="number"
                step="1"
                min={min}
                value={value}
                onChange={(e) => onChange(e.target.value)}
                placeholder={placeholder}
                aria-label={`${op === 'set' ? 'New level' : 'Quantity'} for ${row.name}`}
                className="flex-shrink-0 w-20 px-2 py-1.5 text-sm text-center rounded-lg border border-line bg-surface text-content tabular-nums focus:outline-none focus:ring-2 focus:ring-indigo-500"
            />

            <div className="flex-shrink-0 w-10 text-right text-xs tabular-nums">
                {preview !== null ? <span className={previewClass}>{preview}</span> : <span className="text-content-muted/40">—</span>}
            </div>
        </div>
    );
}
