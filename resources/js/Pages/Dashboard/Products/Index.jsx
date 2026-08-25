import { useState } from 'react';
import { Link, router, usePage } from '@inertiajs/react';
import { Package, Layers, Plus, Upload } from 'lucide-react';
import SaasLayout from '@/Layouts/SaasLayout';
import DataTable from '@/Components/DataTable';
import SearchFilterBar from '@/Components/SearchFilterBar';
import SyncProductsModal from '@/Components/SyncProductsModal';
import ImportProductsModal from '@/Components/Products/ImportProductsModal';
import PublishTargetModal from '@/Components/Products/PublishTargetModal';
import ProductCleanupBar from '@/Components/Products/ProductCleanupBar';
import StatusBadge from '@/Components/StatusBadge';

export default function Index({ store, products = { data: [], links: [] }, filters = {}, connections = [] }) {
    const [search, setSearch] = useState(filters.search ?? '');
    const [syncModalOpen, setSyncModalOpen] = useState(false);
    const permissions = usePage().props.auth?.permissions ?? [];
    const canManage = permissions.includes('*') || permissions.includes('products.manage');

    const activeFilters = {
        platform: filters.platform ?? '',
        connection_id: filters.connection_id ?? '',
        has_listing: filters.has_listing ? '1' : '',
        no_history: filters.no_history ? '1' : '',
        archived: filters.archived ? '1' : '',
    };

    const pushFilters = (next) => {
        const params = {};
        if (search) params.search = search;
        Object.entries(next).forEach(([k, v]) => { if (v) params[k] = v; });
        router.get('/dashboard/products', params, { preserveState: true, preserveScroll: true, replace: true });
    };

    const applyFilters = (q) => {
        setSearch(q);
        const params = {};
        if (q) params.search = q;
        Object.entries(activeFilters).forEach(([k, v]) => { if (v) params[k] = v; });
        router.get('/dashboard/products', params, { preserveState: true, preserveScroll: true, replace: true });
    };

    const onFilterChange = (key, value) => {
        // "yes"/"" pseudo-boolean filters and the platform/connection filters
        // are mutually exclusive on the server (connection_id wins) — clearing
        // platform when a connection is chosen keeps the URL/UI consistent.
        const next = { ...activeFilters, [key]: value };
        if (key === 'connection_id' && value) next.platform = '';
        pushFilters(next);
    };

    const filterOptions = [
        {
            key: 'platform',
            label: 'Imported from',
            options: [
                { value: 'shopify', label: 'Shopify' },
                { value: 'woocommerce', label: 'WooCommerce' },
                { value: 'youcan', label: 'YouCan' },
            ],
        },
        {
            key: 'connection_id',
            label: 'Connection',
            options: connections.map((c) => ({ value: c.id, label: `${c.label ?? c.platform} (${c.platform})` })),
        },
        {
            key: 'has_listing',
            label: 'Channel listing',
            options: [{ value: '1', label: 'Has channel listing' }],
        },
        {
            key: 'no_history',
            label: 'History',
            options: [{ value: '1', label: 'Has no orders/history' }],
        },
        {
            key: 'archived',
            label: 'Status',
            options: [{ value: '1', label: 'Archived only' }],
        },
    ];

    // دالة لتحديث بيانات المنتجات فقط ف الكواليس ملي كيسالي السانكرو بنجاح
    const refreshCatalog = () => {
        router.reload({ only: ['products'] });
    };

    // Publish is explicit-target-only — clicking it never pushes to every
    // connected platform. Both the per-row button and the bulk action open
    // the same channel-selection modal; nothing is published until the user
    // picks connection(s) there and confirms.
    const [publishTarget, setPublishTarget] = useState(null); // { mode:'single', product } | { mode:'bulk', productIds }
    const [selectedIds, setSelectedIds] = useState([]);

    const visibleIds = products.data.map((p) => p.id);
    const allVisibleSelected = visibleIds.length > 0 && visibleIds.every((id) => selectedIds.includes(id));
    const toggleAll = () => setSelectedIds(allVisibleSelected ? [] : visibleIds);
    const toggleOne = (id) => setSelectedIds((prev) => (prev.includes(id) ? prev.filter((x) => x !== id) : [...prev, id]));

    const onBulkPublished = () => {
        setSelectedIds([]);
        router.reload({ only: ['products'] });
    };

    const currency = store?.currency ?? 'MAD';

    const columns = [
        {
            key: 'select',
            label: (
                <input
                    type="checkbox"
                    checked={allVisibleSelected}
                    onChange={toggleAll}
                    className="rounded border-line"
                    aria-label="Select all visible products"
                />
            ),
            width: '36px',
            render: (p) => (
                <input
                    type="checkbox"
                    checked={selectedIds.includes(p.id)}
                    onChange={() => toggleOne(p.id)}
                    className="rounded border-line"
                    aria-label={`Select ${p.name}`}
                />
            ),
        },
        {
            key: 'image',
            label: '',
            width: '52px',
            render: (p) => {
                // 1. تأكدوا واش الحقل عبارة على مصفوفة أو نص يحتاج Parse
                let imagesArray = [];
                try {
                    if (Array.isArray(p.images)) {
                        imagesArray = p.images;
                    } else if (typeof p.images === 'string' && p.images.startsWith('[')) {
                        imagesArray = JSON.parse(p.images);
                    } else if (typeof p.images === 'string' && p.images) {
                        imagesArray = [p.images];
                    }
                } catch (e) {
                    imagesArray = [];
                }

                // 2. ناخدو أول صورة من المصفوفة يلا كانت موجودة
                const firstImage = imagesArray && imagesArray.length > 0 ? imagesArray[0] : null;

                return firstImage ? (
                    <img 
                        src={firstImage} 
                        alt={p.name} 
                        loading="lazy" 
                        className="w-9 h-9 rounded-md object-cover ring-1 ring-line" 
                    />
                ) : (
                    <div className="w-9 h-9 rounded-md bg-surface-3 flex items-center justify-center">
                        <Package className="w-4 h-4 text-content-muted" />
                    </div>
                );
            },
        },
        {
            key: 'name',
            label: 'Product',
            render: (p) => (
                <div>
                    <div className="flex items-center gap-1.5">
                        <span className="text-content font-medium">{p.name}</span>
                        {p.status === 'archived' && (
                            <span className="text-[10px] px-1.5 py-0.5 rounded font-semibold uppercase bg-slate-500/15 text-content-muted">Archived</span>
                        )}
                    </div>
                    <div className="text-xs text-content-muted font-mono">{p.sku}</div>
                </div>
            ),
        },
        { key: 'price', label: 'Price', align: 'right', render: (p) => <span className="font-semibold tabular-nums text-content">{fmtMoney(p.price, currency)}</span> },
        {
            key: 'total_stock',
            label: 'Stock',
            align: 'right',
            render: (p) => {
                const n = Number(p.total_stock ?? 0);
                const tone = n <= 0 ? 'text-red-600 dark:text-red-400' : n <= 10 ? 'text-amber-600 dark:text-amber-400' : 'text-emerald-600 dark:text-emerald-400';
                return <span className={`font-semibold tabular-nums ${tone}`}>{n}</span>;
            },
        },
        { key: 'category', label: 'Category', render: (p) => <span className="text-xs text-content-muted">{p.category ?? '—'}</span> },
        {
            key: 'sync',
            label: 'Sync',
            render: (p) => {
                const listings = p.channel_listings ?? [];
                if (listings.length === 0) return <span className="text-xs text-content-muted/60">Not listed</span>;
                return (
                    <div className="flex flex-wrap gap-1.5">
                        {listings.map((l) => (
                            <div key={l.id} className="flex items-center gap-1">
                                <span className="text-[10px] uppercase text-content-muted">{l.connection?.platform}</span>
                                <StatusBadge type="sync" status={l.sync_status} />
                            </div>
                        ))}
                    </div>
                );
            },
        },
        {
            key: 'actions',
            label: '',
            align: 'right',
            render: (p) => (
                <div className="flex items-center justify-end gap-3">
                    <button
                        type="button"
                        onClick={() => setPublishTarget({ mode: 'single', product: p })}
                        title="Choose which platform(s) to publish this product to"
                        className="inline-flex items-center gap-1 text-xs text-content-muted hover:text-content font-medium"
                    >
                        <Upload className="w-3 h-3" /> Publish
                    </button>
                    <Link
                        // 🆕 تحويل المسار لـ صفحة التعديل الخاصة بالمنتج الحالي باستعمال الـ id ديالو
                        href={`/dashboard/products/${p.id}/edit`}
                        className="inline-flex items-center gap-1 text-xs text-indigo-600 dark:text-indigo-400 hover:text-indigo-700 dark:text-indigo-300 font-medium"
                    >
                        Edit
                    </Link>
                </div>
            ),
        },
    ];

    return (
        <SaasLayout pageHeader={{
            title: 'Products',
            subtitle: 'Catalog across all platforms',
            breadcrumbs: [{ label: 'Dashboard', href: '/dashboard' }, { label: 'Products' }],
            actions: (
                <div className="flex items-center gap-2">
                    <Link
                        href="/dashboard/stock"
                        className="inline-flex items-center gap-1.5 px-3 py-2 text-sm font-medium rounded-lg bg-surface-2 border border-line text-content-muted hover:bg-surface-3 hover:text-content"
                    >
                        <Layers className="w-4 h-4" /> Stock
                    </Link>

                    <ImportProductsModal
                        connections={connections}
                        onImportFromWooCommerce={() => setSyncModalOpen(true)}
                    />

                    {/* مررنا الـ connections والـ onSyncCompleted لي غيعيط ليها الـ Modal */}
                    <SyncProductsModal
                        connections={connections}
                        onSyncCompleted={refreshCatalog}
                        open={syncModalOpen}
                        onOpenChange={setSyncModalOpen}
                    />

                    {canManage && (
                        <Link
                            href="/dashboard/products/create"
                            className="inline-flex items-center gap-1.5 px-3 py-2 text-sm font-semibold rounded-lg bg-indigo-600 text-white hover:bg-indigo-500"
                        >
                            <Plus className="w-4 h-4" /> Add product
                        </Link>
                    )}
                </div>
            ),
        }}>
            {selectedIds.length > 0 && (
                <div className="mb-4 flex items-center justify-between gap-3 px-4 py-2.5 rounded-lg bg-indigo-500/10 border border-indigo-500/30 flex-wrap">
                    <span className="text-sm text-content">{selectedIds.length} product{selectedIds.length === 1 ? '' : 's'} selected</span>
                    <div className="flex items-center gap-2 flex-wrap">
                        <button type="button" onClick={() => setSelectedIds([])} className="text-xs text-content-muted hover:text-content font-medium">
                            Clear
                        </button>
                        <button
                            type="button"
                            onClick={() => setPublishTarget({ mode: 'bulk', productIds: selectedIds })}
                            className="inline-flex items-center gap-1.5 px-3 py-1.5 text-xs font-semibold rounded-lg bg-indigo-600 text-white hover:bg-indigo-500"
                        >
                            <Upload className="w-3.5 h-3.5" /> Publish selected
                        </button>
                        {canManage && (
                            <ProductCleanupBar
                                selectedIds={selectedIds}
                                connections={connections}
                                onClear={() => setSelectedIds([])}
                                onDone={() => router.reload({ only: ['products'] })}
                            />
                        )}
                    </div>
                </div>
            )}

            <div className="mb-4">
                <SearchFilterBar
                    placeholder="Search by name or SKU…"
                    value={search}
                    onSearch={(q) => applyFilters(q)}
                    filters={filterOptions}
                    activeFilters={activeFilters}
                    onFilterChange={onFilterChange}
                />
            </div>

            <DataTable
                columns={columns}
                data={products.data}
                emptyMessage="No products yet. Connect a platform or sync to pull your catalog."
                emptyIcon={Package}
                footer={
                    products.links && products.links.length > 3 ? <Pagination links={products.links} /> : null
                }
            />

            {publishTarget && (
                <PublishTargetModal
                    mode={publishTarget.mode}
                    product={publishTarget.mode === 'single' ? publishTarget.product : undefined}
                    productIds={publishTarget.mode === 'bulk' ? publishTarget.productIds : undefined}
                    productCount={publishTarget.mode === 'bulk' ? publishTarget.productIds.length : undefined}
                    connections={connections}
                    onClose={() => setPublishTarget(null)}
                    onPublished={publishTarget.mode === 'bulk' ? onBulkPublished : refreshCatalog}
                />
            )}
        </SaasLayout>
    );
}

function fmtMoney(value, currency) {
    const n = Number(value) || 0;
    return `${currency} ${n.toLocaleString('en-US', { minimumFractionDigits: 2, maximumFractionDigits: 2 })}`;
}

function Pagination({ links }) {
    return (
        <nav className="flex flex-wrap items-center justify-end gap-1 px-4 py-3">
            {links.map((l, i) => (
                <Link
                    key={i}
                    href={l.url ?? '#'}
                    preserveScroll
                    dangerouslySetInnerHTML={{ __html: l.label }}
                    className={[
                        'min-w-8 px-2.5 py-1 rounded-md text-xs transition',
                        l.active ? 'bg-indigo-600 text-white' : 'text-content-muted hover:bg-surface-3',
                        l.url ? '' : 'opacity-40 pointer-events-none',
                    ].join(' ')}
                />
            ))}
        </nav>
    );
}