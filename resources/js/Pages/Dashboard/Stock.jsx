import { useEffect, useMemo, useState } from 'react';
import { Link, router, usePage } from '@inertiajs/react';
import {
    Boxes, AlertTriangle, DollarSign, History, Settings2, Package, Layers,
    ArrowLeftRight, Warehouse, ChevronDown, LayoutGrid, Table as TableIcon,
} from 'lucide-react';
import SaasLayout from '@/Layouts/SaasLayout';
import StatsCard from '@/Components/StatsCard';
import EmptyState from '@/Components/EmptyState';
import SearchFilterBar from '@/Components/SearchFilterBar';
import AdjustStockModal from '@/Components/Dashboard/AdjustStockModal';

export default function Stock({
    products, stats, lowStockThreshold = 10, filters = {},
    warehouses = [], primaryWarehouseId = null,
}) {
    const permissions = usePage().props?.auth?.permissions ?? [];
    const canTransfer = permissions.includes('stock.adjust') || permissions.includes('stock.manage');

    const [search, setSearch]       = useState(filters.search ?? '');
    const [active, setActive]       = useState({
        low_stock: filters.low_stock ? '1' : '',
        warehouse: filters.warehouse ?? '',
    });
    const [adjusting, setAdjusting] = useState(null);

    // Grid ↔ table preference, remembered across visits.
    const [viewMode, setViewMode] = useState(() => (
        typeof window !== 'undefined' && window.localStorage.getItem('stock.viewMode') === 'table' ? 'table' : 'grid'
    ));
    useEffect(() => {
        try { window.localStorage.setItem('stock.viewMode', viewMode); } catch { /* ignore */ }
    }, [viewMode]);

    const applyFilters = (next = active, nextSearch = search) => {
        const params = { search: nextSearch, low_stock: next.low_stock, warehouse: next.warehouse };
        Object.keys(params).forEach((k) => (params[k] === '' || params[k] == null) && delete params[k]);
        router.get('/dashboard/stock', params, { preserveState: true, preserveScroll: true, replace: true });
    };

    const onSearch       = (q) => { setSearch(q); applyFilters(active, q); };
    const onFilterChange = (k, v) => { const n = { ...active, [k]: v }; setActive(n); applyFilters(n); };

    const productList = products?.data ?? [];
    const isPaginated = Array.isArray(products?.data);

    const scopedName = filters.warehouse ? (warehouses.find((w) => w.id === filters.warehouse)?.name ?? null) : null;

    // The warehouse an Adjust lands in: the filtered one, else the primary.
    const targetWarehouse = useMemo(() => {
        const id = filters.warehouse || primaryWarehouseId;
        if (! id) return null;
        return { id, name: warehouses.find((w) => w.id === id)?.name ?? 'Primary warehouse' };
    }, [filters.warehouse, primaryWarehouseId, warehouses]);

    // Table columns: one per warehouse, narrowed to the filtered one when scoped.
    const warehouseColumns = useMemo(
        () => (filters.warehouse ? warehouses.filter((w) => w.id === filters.warehouse) : warehouses),
        [warehouses, filters.warehouse],
    );

    return (
        <SaasLayout
            pageHeader={{
                title: 'Stock',
                subtitle: scopedName ? `Inventory in ${scopedName}` : 'Inventory levels across all warehouses',
                breadcrumbs: [{ label: 'Dashboard', href: '/dashboard' }, { label: 'Stock' }],
                actions: (
                    <div className="flex items-center gap-2">
                        <Link
                            href="/dashboard/stock/movements"
                            className="inline-flex items-center gap-1.5 px-3 py-2 text-sm font-medium rounded-lg bg-surface-2 border border-line text-content-muted hover:bg-surface-3 hover:text-content transition"
                        >
                            <History className="w-4 h-4" />
                            Movements
                        </Link>
                        {canTransfer && (
                            <Link
                                href="/dashboard/stock/transfers/create"
                                className="inline-flex items-center gap-1.5 px-3 py-2 text-sm font-semibold rounded-lg bg-indigo-600 text-white hover:bg-indigo-500 transition"
                            >
                                <ArrowLeftRight className="w-4 h-4" />
                                Transfer stock
                            </Link>
                        )}
                    </div>
                ),
            }}
        >
            <section className="grid grid-cols-1 md:grid-cols-3 gap-4 mb-6">
                <StatsCard label={scopedName ? `Products in ${scopedName}` : 'Total products'} value={stats.total_products} icon={Boxes} color="indigo" />
                <StatsCard label="Low stock items" value={stats.low_stock_count} icon={AlertTriangle} color="red" sublabel={`≤ ${lowStockThreshold} units`} />
                <StatsCard label="Inventory value" value={`$${Number(stats.total_stock_value).toFixed(2)}`} icon={DollarSign} color="green" />
            </section>

            <div className="mb-4">
                <SearchFilterBar
                    placeholder="Search by name or SKU…"
                    value={search}
                    onSearch={onSearch}
                    filters={[
                        ...(warehouses.length > 0 ? [{
                            key: 'warehouse',
                            label: 'All warehouses',
                            options: warehouses.map((w) => ({ value: w.id, label: w.name })),
                        }] : []),
                        { key: 'low_stock', label: 'Stock level', options: [{ value: '1', label: 'Low stock only' }] },
                    ]}
                    activeFilters={active}
                    onFilterChange={onFilterChange}
                />
            </div>

            <div className="flex items-center justify-between gap-3 mb-4">
                <p className="text-xs text-content-muted">
                    {stats.total_products} {stats.total_products === 1 ? 'product' : 'products'}{scopedName ? ` in ${scopedName}` : ''}
                </p>
                <ViewToggle value={viewMode} onChange={setViewMode} />
            </div>

            {productList.length === 0 ? (
                <div className="bg-surface-2 border border-line rounded-xl">
                    <EmptyState
                        icon={Package}
                        title="No products match your filters"
                        description={scopedName
                            ? `No products are stocked in ${scopedName}. Try another warehouse or clear the filter.`
                            : 'Try clearing the low-stock filter or searching with a different term.'}
                    />
                </div>
            ) : viewMode === 'table' ? (
                <StockTable
                    products={productList}
                    warehouseColumns={warehouseColumns}
                    lowStockThreshold={lowStockThreshold}
                    onAdjust={setAdjusting}
                />
            ) : (
                <div className="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-4">
                    {productList.map((p) => (
                        <ProductCard
                            key={p.id}
                            product={p}
                            lowStockThreshold={lowStockThreshold}
                            scopedWarehouseName={scopedName}
                            onAdjust={() => setAdjusting(p)}
                        />
                    ))}
                </div>
            )}

            {isPaginated && products.links && products.links.length > 3 && (
                <Pagination links={products.links} />
            )}

            {adjusting && (
                <AdjustStockModal
                    product={adjusting}
                    warehouse={targetWarehouse}
                    onClose={() => setAdjusting(null)}
                    onSuccess={() => router.reload({ only: ['products', 'stats'], preserveScroll: true })}
                />
            )}
        </SaasLayout>
    );
}

function ProductCard({ product, lowStockThreshold, scopedWarehouseName, onAdjust }) {
    const [open, setOpen] = useState(false);

    const stock     = Number(product.total_stock ?? product.stock ?? 0);
    const tone      = stockTone(stock, lowStockThreshold);
    const pct       = Math.min(100, Math.max(0, Math.round((stock / (lowStockThreshold * 3)) * 100)));
    const breakdown = product.breakdown ?? [];
    const filtered  = !! scopedWarehouseName;

    // In global view, offer the per-warehouse breakdown when it adds information:
    // stock spread over >1 warehouse, or a variable product's variants to expand.
    const expandable = ! filtered && (breakdown.length > 1 || product.has_variants);

    return (
        <div className="bg-surface-2 border border-line rounded-xl p-5 transition hover:border-content-muted/40">
            <div className="flex items-start justify-between gap-3">
                <div className="min-w-0">
                    <div className="text-sm font-semibold text-content truncate flex items-center gap-1.5">
                        <span className="truncate">{product.name}</span>
                        {product.has_variants && (
                            <span className="inline-flex items-center gap-0.5 flex-shrink-0 px-1.5 py-0.5 rounded-full bg-indigo-500/15 text-indigo-700 dark:text-indigo-300 text-[10px] font-medium">
                                <Layers className="w-2.5 h-2.5" />
                                {product.variant_count}
                            </span>
                        )}
                    </div>
                    <div className="text-xs text-content-muted font-mono truncate">{product.sku}</div>
                    {product.category && (
                        <div className="text-[10px] text-content-muted/60 mt-0.5 uppercase tracking-wider">{product.category}</div>
                    )}
                </div>
                <div className="text-right flex-shrink-0">
                    <span className={`text-sm font-bold tabular-nums px-2 py-0.5 rounded-md ${tone.bg} ${tone.text}`}>
                        {stock}
                    </span>
                    {filtered && <div className="text-[9px] text-content-muted mt-1 uppercase tracking-wider">in {scopedWarehouseName}</div>}
                </div>
            </div>

            <div className="mt-4">
                <div className="h-1.5 rounded-full bg-surface-3 overflow-hidden">
                    <div className={`h-full transition-all ${tone.bar}`} style={{ width: `${pct}%` }} />
                </div>
                <div className="mt-1.5 flex justify-between text-[10px] text-content-muted">
                    <span>{tone.label}</span>
                    <span>{lowStockThreshold * 3}+ ideal</span>
                </div>
            </div>

            {/* Warehouse distribution */}
            {! filtered && breakdown.length > 0 && (
                <div className="mt-3">
                    {expandable ? (
                        <>
                            <button
                                type="button"
                                onClick={() => setOpen((o) => ! o)}
                                className="w-full flex items-center justify-between text-[11px] text-content-muted hover:text-content transition"
                            >
                                <span className="inline-flex items-center gap-1">
                                    <Warehouse className="w-3 h-3" />
                                    {breakdown.length > 1 ? `${breakdown.length} warehouses` : 'By warehouse'}
                                </span>
                                <ChevronDown className={`w-3.5 h-3.5 transition-transform ${open ? 'rotate-180' : ''}`} />
                            </button>
                            {open && (
                                <div className="mt-2 space-y-2">
                                    <div className="flex flex-wrap gap-1.5">
                                        {breakdown.map((w) => <WarehouseChip key={w.warehouse_id} name={w.name} qty={w.quantity} />)}
                                    </div>
                                    {product.has_variants && product.variants?.map((v) => (
                                        <div key={v.id} className="pl-2 border-l-2 border-line/60">
                                            <div className="flex items-center justify-between text-[11px]">
                                                <span className="text-content truncate">{v.name}</span>
                                                <span className="tabular-nums text-content-muted">{v.stock}</span>
                                            </div>
                                            {(v.breakdown ?? []).length > 0 && (
                                                <div className="mt-1 flex flex-wrap gap-1">
                                                    {v.breakdown.map((w) => <WarehouseChip key={w.warehouse_id} name={w.name} qty={w.quantity} small />)}
                                                </div>
                                            )}
                                        </div>
                                    ))}
                                </div>
                            )}
                        </>
                    ) : (
                        <div className="flex flex-wrap gap-1.5">
                            {breakdown.map((w) => <WarehouseChip key={w.warehouse_id} name={w.name} qty={w.quantity} />)}
                        </div>
                    )}
                </div>
            )}

            <div className="mt-4 flex items-end justify-between">
                <div>
                    <div className="text-[10px] text-content-muted uppercase tracking-wider">Price</div>
                    <div className="text-base font-semibold text-content tabular-nums">${Number(product.price).toFixed(2)}</div>
                </div>
                <button
                    type="button"
                    onClick={onAdjust}
                    className="inline-flex items-center gap-1.5 px-3 py-1.5 text-xs font-medium rounded-lg bg-indigo-600 text-white hover:bg-indigo-500 transition"
                >
                    <Settings2 className="w-3.5 h-3.5" />
                    Adjust
                </button>
            </div>
        </div>
    );
}

// Segmented Grid / Table switcher.
function ViewToggle({ value, onChange }) {
    const options = [
        { key: 'grid',  label: 'Grid',  icon: LayoutGrid },
        { key: 'table', label: 'Table', icon: TableIcon },
    ];

    return (
        <div className="inline-flex items-center gap-0.5 p-0.5 rounded-lg bg-surface-2 border border-line" role="group" aria-label="View mode">
            {options.map((o) => {
                const Icon   = o.icon;
                const active = value === o.key;
                return (
                    <button
                        key={o.key}
                        type="button"
                        onClick={() => onChange(o.key)}
                        aria-pressed={active}
                        title={`${o.label} view`}
                        className={`inline-flex items-center gap-1.5 px-2.5 py-1.5 rounded-md text-xs font-medium transition ${
                            active ? 'bg-indigo-600 text-white shadow-sm' : 'text-content-muted hover:text-content hover:bg-surface-3'
                        }`}
                    >
                        <Icon className="w-3.5 h-3.5" />
                        <span className="hidden sm:inline">{o.label}</span>
                    </button>
                );
            })}
        </div>
    );
}

// Responsive table view: a warehouse column per location, horizontal scroll on
// narrow screens (a min-width keeps columns from crushing).
function StockTable({ products, warehouseColumns, lowStockThreshold, onAdjust }) {
    return (
        <div className="bg-surface-2 border border-line rounded-xl overflow-hidden">
            <div className="overflow-x-auto">
                <table className="w-full min-w-[680px] text-sm">
                    <thead>
                        <tr className="text-left text-[11px] uppercase tracking-wider text-content-muted border-b border-line">
                            <th className="px-4 py-3 font-medium">Product</th>
                            <th className="px-4 py-3 font-medium">Total</th>
                            {warehouseColumns.map((w) => (
                                <th key={w.id} className="px-4 py-3 font-medium text-right whitespace-nowrap">{w.name}</th>
                            ))}
                            <th className="px-4 py-3 font-medium text-right">Price</th>
                            <th className="px-4 py-3 font-medium text-right">Actions</th>
                        </tr>
                    </thead>
                    <tbody className="divide-y divide-line/60">
                        {products.map((p) => (
                            <StockTableRow
                                key={p.id}
                                product={p}
                                warehouseColumns={warehouseColumns}
                                lowStockThreshold={lowStockThreshold}
                                onAdjust={onAdjust}
                            />
                        ))}
                    </tbody>
                </table>
            </div>
        </div>
    );
}

function StockTableRow({ product, warehouseColumns, lowStockThreshold, onAdjust }) {
    const stock = Number(product.total_stock ?? 0);
    const tone  = stockTone(stock, lowStockThreshold);
    const pct   = Math.min(100, Math.max(0, Math.round((stock / (lowStockThreshold * 3)) * 100)));
    const qtyAt = (id) => product.breakdown?.find((w) => w.warehouse_id === id)?.quantity ?? 0;

    return (
        <tr className="hover:bg-surface-3/40 transition">
            <td className="px-4 py-3">
                <div className="font-medium text-content flex items-center gap-1.5">
                    <span className="truncate max-w-[220px]">{product.name}</span>
                    {product.has_variants && (
                        <span className="inline-flex items-center gap-0.5 flex-shrink-0 px-1.5 py-0.5 rounded-full bg-indigo-500/15 text-indigo-700 dark:text-indigo-300 text-[10px] font-medium">
                            <Layers className="w-2.5 h-2.5" />
                            {product.variant_count}
                        </span>
                    )}
                </div>
                <div className="text-xs text-content-muted font-mono truncate">{product.sku}</div>
            </td>

            <td className="px-4 py-3">
                <div className="flex items-center gap-2">
                    <span className={`text-xs font-bold tabular-nums px-2 py-0.5 rounded-md ${tone.bg} ${tone.text}`}>{stock}</span>
                    <div className="w-14 h-1.5 rounded-full bg-surface-3 overflow-hidden hidden sm:block" title={tone.label}>
                        <div className={`h-full ${tone.bar}`} style={{ width: `${pct}%` }} />
                    </div>
                </div>
            </td>

            {warehouseColumns.map((w) => {
                const q = qtyAt(w.id);
                return (
                    <td key={w.id} className="px-4 py-3 text-right tabular-nums">
                        {q !== 0 ? <span className="text-content font-medium">{q}</span> : <span className="text-content-muted/40">—</span>}
                    </td>
                );
            })}

            <td className="px-4 py-3 text-right tabular-nums text-content">${Number(product.price).toFixed(2)}</td>

            <td className="px-4 py-3 text-right">
                <button
                    type="button"
                    onClick={() => onAdjust(product)}
                    className="inline-flex items-center gap-1.5 px-2.5 py-1.5 text-xs font-medium rounded-lg bg-indigo-600 text-white hover:bg-indigo-500 transition"
                >
                    <Settings2 className="w-3.5 h-3.5" />
                    Adjust
                </button>
            </td>
        </tr>
    );
}

function WarehouseChip({ name, qty, small = false }) {
    return (
        <span className={`inline-flex items-center gap-1 rounded-md bg-surface-3 ${small ? 'px-1.5 py-0.5 text-[10px]' : 'px-2 py-1 text-[11px]'}`}>
            <span className="text-content-muted truncate max-w-[9rem]">{name}</span>
            <span className="font-semibold tabular-nums text-content">{qty}</span>
        </span>
    );
}

function stockTone(stock, threshold) {
    if (stock <= 0)                          return { bg: 'bg-red-500/15',     text: 'text-red-700 dark:text-red-300',     bar: 'bg-red-500',     label: 'Out of stock' };
    if (stock <= Math.floor(threshold / 2))  return { bg: 'bg-red-500/15',     text: 'text-red-700 dark:text-red-300',     bar: 'bg-red-500',     label: 'Critical' };
    if (stock <= threshold)                  return { bg: 'bg-amber-500/15',   text: 'text-amber-700 dark:text-amber-300',   bar: 'bg-amber-500',   label: 'Low' };
    return                                          { bg: 'bg-emerald-500/15', text: 'text-emerald-700 dark:text-emerald-300', bar: 'bg-emerald-500', label: 'Healthy' };
}

function Pagination({ links }) {
    return (
        <nav className="flex flex-wrap items-center justify-end gap-1 mt-6">
            {links.map((l, i) => (
                <Link
                    key={i}
                    href={l.url ?? '#'}
                    preserveScroll
                    dangerouslySetInnerHTML={{ __html: l.label }}
                    className={[
                        'min-w-8 px-2.5 py-1 rounded-md text-xs transition',
                        l.active ? 'bg-indigo-600 text-white' : 'text-content-muted hover:bg-surface-3 bg-surface-2 border border-line',
                        l.url ? '' : 'opacity-40 pointer-events-none',
                    ].join(' ')}
                />
            ))}
        </nav>
    );
}
