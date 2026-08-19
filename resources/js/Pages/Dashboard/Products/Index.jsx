import { useState } from 'react';
import { Link, router, usePage } from '@inertiajs/react';
import { Package, Layers, Plus } from 'lucide-react';
import SaasLayout from '@/Layouts/SaasLayout';
import DataTable from '@/Components/DataTable';
import SearchFilterBar from '@/Components/SearchFilterBar';
import SyncProductsModal from '@/Components/SyncProductsModal';

export default function Index({ store, products = { data: [], links: [] }, filters = {}, connections = [] }) {
    const [search, setSearch] = useState(filters.search ?? '');
    const permissions = usePage().props.auth?.permissions ?? [];
    const canManage = permissions.includes('*') || permissions.includes('products.manage');

    const applyFilters = (q) => {
        const params = q ? { search: q } : {};
        router.get('/dashboard/products', params, { preserveState: true, preserveScroll: true, replace: true });
    };

    // دالة لتحديث بيانات المنتجات فقط ف الكواليس ملي كيسالي السانكرو بنجاح
    const refreshCatalog = () => {
        router.reload({ only: ['products'] });
    };

    const currency = store?.currency ?? 'MAD';

    const columns = [
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
                
                    <div className="text-content font-medium">{p.name}</div>
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
            key: 'actions',
            label: '',
            align: 'right',
            render: (p) => (
                <Link
                    // 🆕 تحويل المسار لـ صفحة التعديل الخاصة بالمنتج الحالي باستعمال الـ id ديالو
                    href={`/dashboard/products/${p.id}/edit`}
                    className="inline-flex items-center gap-1 text-xs text-indigo-600 dark:text-indigo-400 hover:text-indigo-700 dark:text-indigo-300 font-medium"
                >
                    Edit
                </Link>
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

                    {/* مررنا الـ connections والـ onSyncCompleted لي غيعيط ليها الـ Modal */}
                    <SyncProductsModal
                        connections={connections}
                        onSyncCompleted={refreshCatalog}
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
            <div className="mb-4">
                <SearchFilterBar
                    placeholder="Search by name or SKU…"
                    value={search}
                    onSearch={(q) => { setSearch(q); applyFilters(q); }}
                    filters={[]}
                    activeFilters={{}}
                    onFilterChange={() => {}}
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