import { useEffect, useMemo, useState } from 'react';
import { Link, useForm } from '@inertiajs/react';
import {
    ArrowLeft, ArrowRight, Warehouse, Users, LogOut, Search, Plus, Trash2,
    Layers, PackageSearch, AlertTriangle, Save, Loader2,
} from 'lucide-react';
import SaasLayout from '@/Layouts/SaasLayout';

const KINDS = [
    { key: 'warehouse', label: 'Warehouse', icon: Warehouse, hint: 'Move stock into another of your warehouses.' },
    { key: 'team',      label: 'Team',      icon: Users,     hint: 'Hand stock to an internal team member or post.' },
    { key: 'external',  label: 'External',  icon: LogOut,    hint: 'Goods leaving to an external party.' },
];

const stockKey = (warehouseId, productId, variantId) => `${warehouseId}|${productId}|${variantId ?? ''}`;

const INPUT = 'w-full px-3 py-2 rounded-lg border border-line bg-surface text-content text-sm focus:outline-none focus:ring-2 focus:ring-indigo-500';

export default function StockTransferCreate({ warehouses, primaryWarehouseId, products, stock, members, today }) {
    const { data, setData, post, processing, errors } = useForm({
        source_warehouse_id: primaryWarehouseId ?? (warehouses[0]?.id ?? ''),
        destination_kind: 'warehouse',
        destination_warehouse_id: '',
        destination_member_id: '',
        destination_label: '',
        responsible_member_id: '',
        transfer_date: today,
        notes: '',
        items: [],
    });

    const [query, setQuery]           = useState('');
    const [expanded, setExpanded]     = useState(null); // product id whose variants are shown

    // warehouse_id|product_id|variant_id → quantity on hand
    const stockMap = useMemo(() => {
        const m = new Map();
        stock.forEach((s) => m.set(stockKey(s.warehouse_id, s.product_id, s.variant_id), s.quantity));
        return m;
    }, [stock]);

    const availableAt = (productId, variantId) =>
        stockMap.get(stockKey(data.source_warehouse_id, productId, variantId)) ?? 0;

    const warehouseName = (id) => warehouses.find((w) => w.id === id)?.name ?? '—';

    // When the source changes, clamp every line to what the new source holds.
    useEffect(() => {
        setData('items', data.items.map((it) => {
            const avail = stockMap.get(stockKey(data.source_warehouse_id, it.product_id, it.variant_id)) ?? 0;
            return { ...it, quantity: Math.min(it.quantity, avail) };
        }));
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [data.source_warehouse_id]);

    const hasLine = (productId, variantId) =>
        data.items.some((it) => it.product_id === productId && (it.variant_id ?? '') === (variantId ?? ''));

    const addLine = (product, variant) => {
        if (hasLine(product.id, variant?.id ?? null)) return;
        const avail = availableAt(product.id, variant?.id ?? null);
        setData('items', [...data.items, {
            product_id: product.id,
            variant_id: variant?.id ?? null,
            quantity: avail > 0 ? 1 : 0,
            _name: product.name,
            _variant: variant?.name ?? null,
            _sku: variant?.sku ?? product.sku,
        }]);
        setQuery('');
        setExpanded(null);
    };

    const setQty = (idx, value) => {
        setData('items', data.items.map((it, i) => {
            if (i !== idx) return it;
            const avail = availableAt(it.product_id, it.variant_id);
            const n = parseInt(value, 10);
            const clamped = Number.isNaN(n) ? '' : Math.max(1, Math.min(avail, n));
            return { ...it, quantity: clamped };
        }));
    };

    const removeLine = (idx) => setData('items', data.items.filter((_, i) => i !== idx));

    const filteredProducts = useMemo(() => {
        const q = query.trim().toLowerCase();
        if (! q) return [];
        return products
            .filter((p) => p.name.toLowerCase().includes(q) || (p.sku ?? '').toLowerCase().includes(q))
            .slice(0, 8);
    }, [products, query]);

    const itemsValid = data.items.length > 0 && data.items.every((it) => {
        const avail = availableAt(it.product_id, it.variant_id);
        return Number(it.quantity) >= 1 && Number(it.quantity) <= avail;
    });

    const destinationValid =
        (data.destination_kind === 'warehouse' && data.destination_warehouse_id && data.destination_warehouse_id !== data.source_warehouse_id) ||
        (data.destination_kind !== 'warehouse' && data.destination_label.trim() !== '');

    const submit = (e) => {
        e.preventDefault();
        post('/dashboard/stock/transfers');
    };

    return (
        <SaasLayout
            pageHeader={{
                title: 'New stock transfer',
                subtitle: 'Move stock out and generate a Bon de Sortie',
                breadcrumbs: [
                    { label: 'Dashboard', href: '/dashboard' },
                    { label: 'Stock', href: '/dashboard/stock' },
                    { label: 'Transfers', href: '/dashboard/stock/transfers' },
                    { label: 'New' },
                ],
                actions: (
                    <Link href="/dashboard/stock/transfers" className="inline-flex items-center gap-1.5 px-3 py-2 text-sm font-medium rounded-lg bg-surface-2 border border-line text-content-muted hover:bg-surface-3 hover:text-content transition">
                        <ArrowLeft className="w-4 h-4" />
                        Back
                    </Link>
                ),
            }}
        >
            <form onSubmit={submit} className="grid grid-cols-1 lg:grid-cols-3 gap-6 max-w-6xl">
                {/* Left: routing + items */}
                <div className="lg:col-span-2 space-y-6">
                    {/* Route */}
                    <div className="bg-surface-2 border border-line rounded-xl p-5 space-y-4">
                        <h3 className="text-sm font-semibold text-content flex items-center gap-2">
                            <ArrowRight className="w-4 h-4 text-indigo-500" /> Route
                        </h3>

                        <div className="grid grid-cols-1 sm:grid-cols-2 gap-4">
                            <Field label="Source warehouse" error={errors.source_warehouse_id}>
                                <select
                                    value={data.source_warehouse_id}
                                    onChange={(e) => setData('source_warehouse_id', e.target.value)}
                                    className={INPUT}
                                >
                                    {warehouses.map((w) => (
                                        <option key={w.id} value={w.id}>{w.name}{w.type !== 'standard' ? ` (${w.type})` : ''}</option>
                                    ))}
                                </select>
                            </Field>

                            <Field label="Destination" error={errors.destination_warehouse_id || errors.destination_label}>
                                <div className="flex gap-1.5">
                                    {KINDS.map((k) => {
                                        const Icon = k.icon;
                                        const active = data.destination_kind === k.key;
                                        return (
                                            <button
                                                key={k.key}
                                                type="button"
                                                onClick={() => setData('destination_kind', k.key)}
                                                className={`flex-1 inline-flex items-center justify-center gap-1.5 px-2 py-2 rounded-lg text-xs font-medium border transition ${
                                                    active ? 'border-indigo-500 bg-indigo-500/10 text-indigo-700 dark:text-indigo-300' : 'border-line bg-surface text-content-muted hover:text-content'
                                                }`}
                                            >
                                                <Icon className="w-3.5 h-3.5" />
                                                {k.label}
                                            </button>
                                        );
                                    })}
                                </div>
                            </Field>
                        </div>

                        {/* Destination detail switches on kind */}
                        {data.destination_kind === 'warehouse' ? (
                            <Field label="Destination warehouse" error={errors.destination_warehouse_id}>
                                <select
                                    value={data.destination_warehouse_id}
                                    onChange={(e) => setData('destination_warehouse_id', e.target.value)}
                                    className={INPUT}
                                >
                                    <option value="">Select a warehouse…</option>
                                    {warehouses.filter((w) => w.id !== data.source_warehouse_id).map((w) => (
                                        <option key={w.id} value={w.id}>{w.name}{w.type !== 'standard' ? ` (${w.type})` : ''}</option>
                                    ))}
                                </select>
                            </Field>
                        ) : (
                            <div className="grid grid-cols-1 sm:grid-cols-2 gap-4">
                                {data.destination_kind === 'team' && (
                                    <Field label="Team member (optional)" error={errors.destination_member_id}>
                                        <select
                                            value={data.destination_member_id}
                                            onChange={(e) => {
                                                const id = e.target.value;
                                                const name = members.find((m) => m.id === id)?.name ?? '';
                                                setData((d) => ({ ...d, destination_member_id: id, destination_label: name || d.destination_label }));
                                            }}
                                            className={INPUT}
                                        >
                                            <option value="">Select a member…</option>
                                            {members.map((m) => <option key={m.id} value={m.id}>{m.name}</option>)}
                                        </select>
                                    </Field>
                                )}
                                <Field label={data.destination_kind === 'team' ? 'Destination label' : 'Destination / party'} error={errors.destination_label}>
                                    <input
                                        type="text"
                                        value={data.destination_label}
                                        onChange={(e) => setData('destination_label', e.target.value)}
                                        placeholder={data.destination_kind === 'team' ? 'e.g. Sales team — North' : 'e.g. Pop-up event, partner store'}
                                        className={INPUT}
                                    />
                                </Field>
                            </div>
                        )}
                    </div>

                    {/* Items */}
                    <div className="bg-surface-2 border border-line rounded-xl p-5 space-y-4">
                        <div className="flex items-center justify-between">
                            <h3 className="text-sm font-semibold text-content flex items-center gap-2">
                                <Layers className="w-4 h-4 text-indigo-500" /> Items
                            </h3>
                            <span className="text-xs text-content-muted">Available at {warehouseName(data.source_warehouse_id)}</span>
                        </div>

                        {/* Product search */}
                        <div className="relative">
                            <Search className="w-4 h-4 absolute left-3 top-1/2 -translate-y-1/2 text-content-muted pointer-events-none" />
                            <input
                                type="text"
                                value={query}
                                onChange={(e) => { setQuery(e.target.value); setExpanded(null); }}
                                placeholder="Search products by name or SKU to add…"
                                className="w-full pl-9 pr-3 py-2 rounded-lg border border-line bg-surface text-content text-sm focus:outline-none focus:ring-2 focus:ring-indigo-500"
                            />
                            {query.trim() !== '' && (
                                <div className="absolute z-20 mt-1 w-full rounded-lg border border-line bg-surface-2 shadow-xl max-h-72 overflow-y-auto">
                                    {filteredProducts.length === 0 ? (
                                        <p className="px-3 py-4 text-center text-xs text-content-muted">No products with stock match “{query}”.</p>
                                    ) : filteredProducts.map((p) => (
                                        <ProductResult
                                            key={p.id}
                                            product={p}
                                            expanded={expanded === p.id}
                                            onToggle={() => setExpanded(expanded === p.id ? null : p.id)}
                                            availableAt={availableAt}
                                            hasLine={hasLine}
                                            onAdd={addLine}
                                        />
                                    ))}
                                </div>
                            )}
                        </div>

                        {/* Selected lines */}
                        {data.items.length === 0 ? (
                            <div className="rounded-lg border border-dashed border-line px-4 py-8 text-center">
                                <PackageSearch className="w-6 h-6 mx-auto text-content-muted/50" />
                                <p className="mt-2 text-xs text-content-muted">No items yet — search above to add products to this transfer.</p>
                            </div>
                        ) : (
                            <div className="rounded-lg border border-line divide-y divide-line/60">
                                {data.items.map((it, idx) => {
                                    const avail = availableAt(it.product_id, it.variant_id);
                                    const over  = Number(it.quantity) > avail || Number(it.quantity) < 1;
                                    const rowErr = errors[`items.${idx}.quantity`] || errors[`items.${idx}.variant_id`] || errors[`items.${idx}.product_id`];
                                    return (
                                        <div key={`${it.product_id}-${it.variant_id ?? ''}`} className="flex items-center gap-3 px-3 py-2.5">
                                            <div className="min-w-0 flex-1">
                                                <div className="text-sm font-medium text-content truncate">
                                                    {it._name}
                                                    {it._variant && <span className="ml-1.5 inline-flex items-center px-1.5 py-0.5 rounded bg-indigo-500/10 text-indigo-700 dark:text-indigo-300 text-[10px]">{it._variant}</span>}
                                                </div>
                                                <div className="text-[11px] text-content-muted font-mono truncate">{it._sku}</div>
                                                {rowErr && <div className="text-[11px] text-red-600 dark:text-red-400 mt-0.5">{rowErr}</div>}
                                            </div>
                                            <div className="flex-shrink-0 text-right">
                                                <div className="text-[10px] text-content-muted uppercase tracking-wider">Avail</div>
                                                <div className={`text-sm font-semibold tabular-nums ${avail === 0 ? 'text-red-500' : 'text-content'}`}>{avail}</div>
                                            </div>
                                            <input
                                                type="number" min="1" max={avail} step="1"
                                                value={it.quantity}
                                                onChange={(e) => setQty(idx, e.target.value)}
                                                aria-label={`Quantity for ${it._name}`}
                                                className={`flex-shrink-0 w-20 px-2 py-1.5 text-sm text-center rounded-lg border bg-surface text-content tabular-nums focus:outline-none focus:ring-2 focus:ring-indigo-500 ${over ? 'border-red-500' : 'border-line'}`}
                                            />
                                            <button type="button" onClick={() => removeLine(idx)} className="flex-shrink-0 p-1.5 text-content-muted hover:text-red-500 transition" aria-label="Remove item">
                                                <Trash2 className="w-4 h-4" />
                                            </button>
                                        </div>
                                    );
                                })}
                            </div>
                        )}
                        {errors.items && <p className="text-xs text-red-600 dark:text-red-400">{errors.items}</p>}
                    </div>
                </div>

                {/* Right: details + submit */}
                <div className="space-y-6">
                    <div className="bg-surface-2 border border-line rounded-xl p-5 space-y-4">
                        <h3 className="text-sm font-semibold text-content">Details</h3>

                        <Field label="Transfer date" error={errors.transfer_date}>
                            <input type="date" value={data.transfer_date} onChange={(e) => setData('transfer_date', e.target.value)} className={INPUT} />
                        </Field>

                        <Field label="Responsible member" error={errors.responsible_member_id}>
                            <select value={data.responsible_member_id} onChange={(e) => setData('responsible_member_id', e.target.value)} className={INPUT}>
                                <option value="">Unassigned</option>
                                {members.map((m) => <option key={m.id} value={m.id}>{m.name}</option>)}
                            </select>
                        </Field>

                        <Field label="Reference notes" error={errors.notes}>
                            <textarea rows={3} value={data.notes} onChange={(e) => setData('notes', e.target.value)} placeholder="Optional context printed on the slip" className={`${INPUT} resize-none`} />
                        </Field>
                    </div>

                    <div className="bg-surface-2 border border-line rounded-xl p-5 space-y-3">
                        <div className="flex items-center justify-between text-sm">
                            <span className="text-content-muted">Items</span>
                            <span className="font-semibold text-content tabular-nums">{data.items.length}</span>
                        </div>
                        <div className="flex items-center justify-between text-sm">
                            <span className="text-content-muted">Total units</span>
                            <span className="font-semibold text-content tabular-nums">{data.items.reduce((s, it) => s + (Number(it.quantity) || 0), 0)}</span>
                        </div>

                        {! destinationValid && data.items.length > 0 && (
                            <p className="text-[11px] text-amber-600 dark:text-amber-400 flex items-center gap-1.5">
                                <AlertTriangle className="w-3.5 h-3.5" /> Choose a valid destination.
                            </p>
                        )}

                        <button
                            type="submit"
                            disabled={processing || ! itemsValid || ! destinationValid}
                            className="w-full inline-flex items-center justify-center gap-2 px-4 py-2.5 rounded-lg bg-indigo-600 text-white text-sm font-semibold hover:bg-indigo-500 disabled:opacity-50 disabled:cursor-not-allowed transition"
                        >
                            {processing ? <><Loader2 className="w-4 h-4 animate-spin" /> Recording…</> : <><Save className="w-4 h-4" /> Record transfer</>}
                        </button>
                        <p className="text-[11px] text-content-muted text-center">A Bon de Sortie is generated once recorded.</p>
                    </div>
                </div>
            </form>

            <style>{`
                .input-base {
                    width: 100%;
                    padding: 0.5rem 0.75rem;
                    border-radius: 0.5rem;
                    border: 1px solid rgb(var(--line, 229 231 235) / 1);
                }
            `}</style>
        </SaasLayout>
    );
}

function ProductResult({ product, expanded, onToggle, availableAt, hasLine, onAdd }) {
    if (! product.has_variants) {
        const avail = availableAt(product.id, null);
        const added = hasLine(product.id, null);
        return (
            <button
                type="button"
                disabled={avail === 0 || added}
                onClick={() => onAdd(product, null)}
                className="w-full flex items-center gap-3 px-3 py-2.5 text-left hover:bg-surface-3/60 disabled:opacity-40 disabled:cursor-not-allowed transition"
            >
                <div className="min-w-0 flex-1">
                    <div className="text-sm text-content truncate">{product.name}</div>
                    <div className="text-[11px] text-content-muted font-mono truncate">{product.sku}</div>
                </div>
                <span className="text-xs text-content-muted tabular-nums">{avail} avail</span>
                {added ? <span className="text-[10px] text-emerald-500">Added</span> : <Plus className="w-4 h-4 text-indigo-500" />}
            </button>
        );
    }

    return (
        <div>
            <button type="button" onClick={onToggle} className="w-full flex items-center gap-3 px-3 py-2.5 text-left hover:bg-surface-3/60 transition">
                <div className="min-w-0 flex-1">
                    <div className="text-sm text-content truncate flex items-center gap-1.5">
                        {product.name}
                        <span className="inline-flex items-center gap-0.5 px-1.5 py-0.5 rounded-full bg-indigo-500/15 text-indigo-700 dark:text-indigo-300 text-[10px]">
                            <Layers className="w-2.5 h-2.5" />{product.variants.length}
                        </span>
                    </div>
                    <div className="text-[11px] text-content-muted font-mono truncate">{product.sku}</div>
                </div>
                <span className="text-[11px] text-content-muted">{expanded ? 'Hide' : 'Pick variant'}</span>
            </button>
            {expanded && (
                <div className="bg-surface/60 border-t border-line/60 divide-y divide-line/40">
                    {product.variants.map((v) => {
                        const avail = availableAt(product.id, v.id);
                        const added = hasLine(product.id, v.id);
                        return (
                            <button
                                key={v.id}
                                type="button"
                                disabled={avail === 0 || added}
                                onClick={() => onAdd(product, v)}
                                className="w-full flex items-center gap-3 pl-6 pr-3 py-2 text-left hover:bg-surface-3/60 disabled:opacity-40 disabled:cursor-not-allowed transition"
                            >
                                <div className="min-w-0 flex-1">
                                    <div className="text-sm text-content truncate">{v.name}</div>
                                    <div className="text-[11px] text-content-muted font-mono truncate">{v.sku}</div>
                                </div>
                                <span className="text-xs text-content-muted tabular-nums">{avail} avail</span>
                                {added ? <span className="text-[10px] text-emerald-500">Added</span> : <Plus className="w-4 h-4 text-indigo-500" />}
                            </button>
                        );
                    })}
                </div>
            )}
        </div>
    );
}

function Field({ label, error, children }) {
    return (
        <label className="block">
            <span className="text-xs text-content-muted font-medium">{label}</span>
            <div className="mt-1">{children}</div>
            {error && <span className="text-[11px] text-red-600 dark:text-red-400 mt-1 block">{error}</span>}
        </label>
    );
}
