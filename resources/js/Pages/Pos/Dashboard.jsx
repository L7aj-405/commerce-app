import { useMemo, useState } from 'react';
import axios from 'axios';
import { Head } from '@inertiajs/react';
import { SlidersHorizontal } from 'lucide-react';
import useCart from '@/Hooks/useCart';
import usePersistentState from '@/Hooks/usePersistentState';
import useProductFilters from '@/Hooks/useProductFilters';
import PosLayout from '@/Layouts/PosLayout';
import SearchBar from './Components/SearchBar';
import ProductGrid from './Components/ProductGrid';
import ProductViewControls, { VIEW_DEFAULT_COLUMNS } from './Components/ProductViewControls';
import Cart from './Components/Cart';
import VariantModal from './Components/VariantModal';
import InvoiceModal from './Components/InvoiceModal'; // 👈 استيراد المكون ديال الفاتورة هنا
import FilterSidebar from '@/Components/Filters/FilterSidebar';
import ActiveFilterChips from '@/Components/Filters/ActiveFilterChips';

const stockStatus = (stock) => (stock <= 0 ? 'out' : stock <= 5 ? 'low' : 'in');

export default function Dashboard({ store, products, categories = [], activeSession, cashier }) {
    const currency = store?.currency ?? '$';
    const taxRate  = Number(store?.tax_rate ?? 0);

    const cart = useCart({ taxRate });

    const [query, setQuery] = useState('');
    const [filterDrawer, setFilterDrawer] = useState(false);

    // Structured filters — config-driven, so new attributes are drop-in.
    const filterConfig = useMemo(() => {
        const hasBrand = products.some((p) => p.brand);
        return [
            {
                key: 'category', label: 'Category', type: 'checkbox', accessor: (p) => p.category,
                options: categories.length ? categories.map((c) => ({ value: c, label: c })) : undefined,
            },
            ...(hasBrand ? [{ key: 'brand', label: 'Brand', type: 'checkbox', accessor: (p) => p.brand }] : []),
            {
                key: 'price', label: 'Price', type: 'range', accessor: (p) => Number(p.price),
                step: 1, format: (v) => `${currency} ${v}`,
            },
            {
                key: 'stock', label: 'Availability', type: 'checkbox', accessor: (p) => stockStatus(p.stock),
                options: [
                    { value: 'in', label: 'In stock' },
                    { value: 'low', label: 'Low stock' },
                    { value: 'out', label: 'Out of stock' },
                ],
            },
        ];
    }, [products, categories, currency]);

    const pf = useProductFilters(filterConfig, products);

    // Product display layout — persisted across sessions.
    const [view, setView]       = usePersistentState('pos.view', 'large');
    const [columns, setColumns] = usePersistentState('pos.columns', VIEW_DEFAULT_COLUMNS.large);

    const handleViewChange = (next) => {
        setView(next);
        // Switching to a card view applies that view's sensible items-per-row default.
        if (VIEW_DEFAULT_COLUMNS[next]) setColumns(VIEW_DEFAULT_COLUMNS[next]);
    };

    const [isSubmitting, setIsSubmitting] = useState(false);
    const [error, setError] = useState(null);
    const [lastReceipt, setLastReceipt] = useState(null);
    
    // 🆕 ستايت جديد باش نتحكمو ف الـ Modal ديال الطباعة
    const [showInvoice, setShowInvoice] = useState(false);
    const [printData, setPrintData] = useState(null);

    // Variable products open the variant picker instead of adding straight to the
    // cart; simple products are added directly.
    const [variantProduct, setVariantProduct] = useState(null);
    const handleProductClick = (product) => {
        if (product.has_variants) {
            setVariantProduct(product);
        } else {
            cart.addItem(product);
        }
    };

    // Text search runs on top of the structured filter results.
    const visible = useMemo(() => {
        const q = query.trim().toLowerCase();
        if (q === '') return pf.filtered;
        return pf.filtered.filter((p) =>
            p.name.toLowerCase().includes(q) || (p.sku ?? '').toLowerCase().includes(q),
        );
    }, [pf.filtered, query]);

    const submitCheckout = async () => {
        if (!activeSession) {
            setError('No open session. Open one before charging.');
            return false;
        }
        if (cart.items.length === 0) {
            setError('Cart is empty.');
            return false;
        }

        setError(null);
        setIsSubmitting(true);

        const payload = {
            items: cart.items.map((row) => {
                const sub = row.unit_price * row.quantity;
                const disc = sub * (row.discount_percent / 100);
                return {
                    product_id:       row.product_id,
                    variant_id:       row.variant_id ?? null,
                    // Fold the variant label into the printed line name so the
                    // receipt/invoice reads e.g. "T-Shirt — M / Red".
                    product_name:     row.variant_name ? `${row.product_name} — ${row.variant_name}` : row.product_name,
                    product_sku:      row.product_sku,
                    unit_price:       row.unit_price,
                    quantity:         row.quantity,
                    subtotal:         round2(sub),
                    line_total:       round2(sub - disc),
                    discount_percent: row.discount_percent,
                    discount_amount:  round2(disc),
                    tax_percent:      taxRate * 100,
                    tax_amount:       round2((sub - disc) * taxRate),
                };
            }),
            subtotal:        cart.subtotal,
            tax_amount:      cart.taxAmount,
            discount_amount: cart.discountAmount,
            total_amount:    cart.total,
            payment_method:  cart.paymentMethod,
            amount_paid:     cart.amountPaid,
            change_amount:   cart.change,
            customer_name:   cart.customerName || null,
            customer_phone:  cart.customerPhone || null,
            customer_email:  cart.customerEmail || null,
            customer_type:   cart.customerType,
            company_name:    cart.companyName || null,
            tax_id:          cart.taxId || null,
            notes:           cart.notes || null,
            fulfillment_type: cart.fulfillmentType,
            delivery_address: cart.fulfillmentType === 'delivery' ? (cart.deliveryAddress || null) : null,
            delivery_fee:     cart.fulfillmentType === 'delivery' ? cart.deliveryCharge : 0,
        };

        try {
            const res = await axios.post('/pos/checkout', payload, {
                headers: { 'X-Requested-With': 'XMLHttpRequest' },
            });
            
            setLastReceipt(res.data);

            // 🆕 نجمعو داتا ديال الطباعة (الـ response من الباكيند + السلة الحالية لي فيها أسماء المنتجات)
            setPrintData({
                receipt_number: res.data.receipt_number,
                total_amount: res.data.total_amount,
                delivery_fee: payload.delivery_fee,
                amount_paid: payload.amount_paid,
                change_amount: payload.change_amount,
                customer_name: payload.customer_name,
                customer_type: payload.customer_type,   // drives thermal vs A4 auto-select
                company_name: payload.company_name,
                tax_id: payload.tax_id,
                payment_method: payload.payment_method,
                // Fold the variant label into the printed name so the thermal
                // slip matches the server-generated receipt ("T-Shirt — M / Red").
                items: cart.items.map((row) => ({
                    ...row,
                    product_name: row.variant_name ? `${row.product_name} — ${row.variant_name}` : row.product_name,
                })), // خديناهم من الكارت قبل ما تمسح
            });

            // Instant sales hand the goods over now → print immediately. Delivery
            // orders are queued to the board; the cashier can still Re-print a slip.
            if (! res.data.is_delivery) {
                setShowInvoice(true);
            }

            cart.clearCart();
            return true;
        } catch (e) {
            const msg = e?.response?.data?.message
                ?? (e?.response?.data?.errors
                    ? Object.values(e.response.data.errors).flat().join(' ')
                    : null)
                ?? 'Checkout failed. Please try again.';
            setError(msg);
            return false;
        } finally {
            setIsSubmitting(false);
        }
    };

    return (
        <PosLayout store={store} sessionOpen={!! activeSession}>
            <Head title="POS — Terminal" />

            <div className="flex-1 min-h-0 flex flex-col text-content">
                {lastReceipt && (
                    <div className="px-4 py-2 bg-emerald-500/10 border-b border-emerald-500/30 text-emerald-800 dark:text-emerald-200 text-xs flex items-center justify-between no-print">
                        <span>
                            {lastReceipt.is_delivery ? 'Delivery order' : 'Receipt'}{' '}
                            <strong className="font-mono">{lastReceipt.receipt_number}</strong>{' '}
                            {lastReceipt.is_delivery ? 'queued for fulfillment' : 'saved'} · Total{' '}
                            {currency}
                            {Number(lastReceipt.total_amount).toFixed(2)}
                        </span>
                        <div className="flex gap-3">
                            <button
                                type="button"
                                onClick={() => setShowInvoice(true)} // إعادة فتح نافذة الطبع يلا سدها الكيشير بغلط
                                className="text-indigo-600 dark:text-indigo-400 hover:text-content underline"
                            >
                                Re-print
                            </button>
                            <button
                                type="button"
                                onClick={() => setLastReceipt(null)}
                                className="text-emerald-700 dark:text-emerald-300 hover:text-content"
                            >
                                Dismiss
                            </button>
                        </div>
                    </div>
                )}

                <div className="flex-1 flex flex-col lg:flex-row min-h-0">
                    <FilterSidebar
                        {...pf}
                        className="lg:py-4 lg:pl-4"
                        open={filterDrawer}
                        onClose={() => setFilterDrawer(false)}
                    />

                    <main className="flex-1 flex flex-col min-w-0 min-h-0 p-4 gap-4">
                        <div className="flex flex-col sm:flex-row sm:items-center gap-3">
                            <button
                                type="button"
                                onClick={() => setFilterDrawer(true)}
                                className="lg:hidden inline-flex items-center gap-2 px-3 py-2 rounded-lg bg-surface-2 border border-line text-sm font-medium text-content-muted hover:text-content"
                            >
                                <SlidersHorizontal className="w-4 h-4" />
                                Filters
                                {pf.activeCount > 0 && (
                                    <span className="inline-flex items-center justify-center min-w-5 h-5 px-1.5 text-[11px] font-semibold rounded-full bg-indigo-500/15 text-indigo-700 dark:text-indigo-300">
                                        {pf.activeCount}
                                    </span>
                                )}
                            </button>
                            <div className="flex-1 min-w-0">
                                <SearchBar
                                    query={query}
                                    onQueryChange={setQuery}
                                    categories={[]}
                                />
                            </div>
                            <ProductViewControls
                                view={view}
                                columns={columns}
                                onViewChange={handleViewChange}
                                onColumnsChange={setColumns}
                            />
                        </div>

                        <ActiveFilterChips chips={pf.chips} onClearAll={pf.clearAll} />

                        <div className="flex-1 min-h-0 overflow-y-auto -mx-1 px-1">
                            <ProductGrid
                                products={visible}
                                currency={currency}
                                onAdd={handleProductClick}
                                view={view}
                                columns={columns}
                            />
                        </div>
                    </main>

                    <Cart
                        cart={cart}
                        currency={currency}
                        onSubmit={submitCheckout}
                        isSubmitting={isSubmitting}
                        error={error}
                    />
                </div>
            </div>

            {/* Variant picker — opens when a variable product is tapped */}
            {variantProduct && (
                <VariantModal
                    product={variantProduct}
                    currency={currency}
                    onAdd={cart.addItem}
                    onClose={() => setVariantProduct(null)}
                />
            )}

            {/* 🆕 الـ Modal ديال الفاتورة الحرارية لي غايبان ويطبع آلياً */}
            {showInvoice && printData && (
                <InvoiceModal 
                    invoice={printData} 
                    currency={currency}
                    onClose={() => setShowInvoice(false)} 
                />
            )}
        </PosLayout>
    );
}

function round2(n) {
    return Math.round(n * 100) / 100;
}