import { useEffect } from 'react';
import { X, User, Building2, Receipt, FileText, Loader2, Zap, Truck } from 'lucide-react';

/**
 * Final confirmation before the sale is committed. Shows a clean summary of
 * items, customer and totals, and tells the cashier which document will print
 * (thermal for individuals, A4 invoice for companies). Validating here is what
 * actually calls the checkout endpoint.
 */
export default function CheckoutPreviewModal({ cart, currency = '$', isSubmitting, error, onBack, onValidate }) {
    const isCompany  = cart.customerType === 'company';
    const isDelivery = cart.fulfillmentType === 'delivery';

    // Close on Escape (unless mid-submit).
    useEffect(() => {
        const onKey = (e) => e.key === 'Escape' && ! isSubmitting && onBack();
        window.addEventListener('keydown', onKey);
        return () => window.removeEventListener('keydown', onKey);
    }, [isSubmitting, onBack]);

    return (
        <div className="fixed inset-0 z-50 flex items-center justify-center p-4 no-print">
            <div className="absolute inset-0 bg-black/50 backdrop-blur-sm" onClick={() => ! isSubmitting && onBack()} />

            <div className="relative w-full max-w-md max-h-[90vh] flex flex-col bg-surface border border-line rounded-2xl shadow-2xl text-content">
                <header className="flex items-center justify-between px-5 py-4 border-b border-line">
                    <div>
                        <h2 className="text-base font-semibold">Confirm order</h2>
                        <p className="text-xs text-content-muted">Review before charging the customer.</p>
                    </div>
                    <button
                        type="button"
                        onClick={onBack}
                        disabled={isSubmitting}
                        className="p-1.5 rounded-lg text-content-muted hover:text-content hover:bg-surface-2 disabled:opacity-50 transition"
                        aria-label="Back"
                    >
                        <X className="w-5 h-5" />
                    </button>
                </header>

                <div className="flex-1 overflow-y-auto px-5 py-4 space-y-4 text-sm">
                    {/* Fulfillment method */}
                    <div className={`flex items-center gap-2.5 px-3 py-2.5 rounded-lg border ${
                        isDelivery
                            ? 'border-amber-500/40 bg-amber-500/10 text-amber-700 dark:text-amber-300'
                            : 'border-emerald-500/40 bg-emerald-500/10 text-emerald-700 dark:text-emerald-300'
                    }`}>
                        {isDelivery ? <Truck className="w-4 h-4 shrink-0" /> : <Zap className="w-4 h-4 shrink-0" />}
                        <span className="text-xs font-medium">
                            {isDelivery
                                ? 'Delivery — queued to Order Management as Pending'
                                : 'Instant pickup — completes now'}
                        </span>
                    </div>

                    {isDelivery && cart.deliveryAddress && (
                        <Section title="Delivery address">
                            <p className="text-content whitespace-pre-line">{cart.deliveryAddress}</p>
                        </Section>
                    )}

                    {/* Document that will print */}
                    <div className={`flex items-center gap-2.5 px-3 py-2.5 rounded-lg border ${
                        isCompany
                            ? 'border-blue-500/40 bg-blue-500/10 text-blue-700 dark:text-blue-300'
                            : 'border-indigo-500/40 bg-indigo-500/10 text-indigo-700 dark:text-indigo-300'
                    }`}>
                        {isCompany ? <FileText className="w-4 h-4 shrink-0" /> : <Receipt className="w-4 h-4 shrink-0" />}
                        <span className="text-xs font-medium">
                            Will print: {isCompany ? 'A4 invoice (Facture)' : '80mm thermal receipt'}
                        </span>
                    </div>

                    {/* Customer */}
                    <Section title="Customer">
                        <div className="flex items-center gap-2">
                            {isCompany ? <Building2 className="w-4 h-4 text-content-muted" /> : <User className="w-4 h-4 text-content-muted" />}
                            <span className="text-content">
                                {isCompany
                                    ? (cart.companyName || 'Company')
                                    : (cart.customerName || 'Walk-in customer')}
                            </span>
                        </div>
                        {isCompany && cart.taxId && <Meta label="Tax ID / ICE" value={cart.taxId} />}
                        {isCompany && cart.customerName && <Meta label="Contact" value={cart.customerName} />}
                        {cart.customerPhone && <Meta label="Phone" value={cart.customerPhone} />}
                        {cart.customerEmail && <Meta label="Email" value={cart.customerEmail} />}
                    </Section>

                    {/* Items */}
                    <Section title={`Items (${cart.itemCount})`}>
                        <ul className="divide-y divide-line/50 -my-1.5">
                            {cart.items.map((row) => {
                                const lineTotal = row.unit_price * row.quantity * (1 - row.discount_percent / 100);
                                return (
                                    <li key={row.line_id} className="flex items-center justify-between gap-3 py-1.5">
                                        <span className="min-w-0 truncate">
                                            <span className="text-content-muted tabular-nums">{row.quantity}×</span>{' '}
                                            <span className="text-content">{row.product_name}</span>
                                            {row.variant_name && (
                                                <span className="text-content-muted"> · {row.variant_name}</span>
                                            )}
                                        </span>
                                        <span className="shrink-0 tabular-nums text-content">{currency}{lineTotal.toFixed(2)}</span>
                                    </li>
                                );
                            })}
                        </ul>
                    </Section>

                    {/* Totals */}
                    <Section title="Totals">
                        <Line label="Subtotal" value={`${currency}${cart.subtotal.toFixed(2)}`} />
                        {cart.discountAmount > 0 && <Line label="Discount" value={`−${currency}${cart.discountAmount.toFixed(2)}`} tone="red" />}
                        {cart.taxAmount > 0 && <Line label={`Tax (${(cart.taxRate * 100).toFixed(0)}%)`} value={`${currency}${cart.taxAmount.toFixed(2)}`} />}
                        {isDelivery && (
                            <Line
                                label="Delivery fee"
                                value={cart.deliveryCharge > 0 ? `${currency}${cart.deliveryCharge.toFixed(2)}` : 'Free'}
                                tone={cart.deliveryCharge > 0 ? undefined : 'emerald'}
                            />
                        )}
                        <div className="flex items-center justify-between pt-2 mt-1 border-t border-line">
                            <span className="font-semibold text-content">Total</span>
                            <span className="text-lg font-bold tabular-nums text-content">{currency}{cart.total.toFixed(2)}</span>
                        </div>
                        <Line label={`Paid (${cart.paymentMethod})`} value={`${currency}${Number(cart.amountPaid).toFixed(2)}`} />
                        <Line label="Change" value={`${currency}${cart.change.toFixed(2)}`} tone="emerald" />
                    </Section>

                    {error && (
                        <div className="rounded-md bg-red-500/10 border border-red-500/30 px-3 py-2 text-red-700 dark:text-red-300 text-xs">
                            {error}
                        </div>
                    )}
                </div>

                <footer className="grid grid-cols-2 gap-2 px-5 py-4 border-t border-line">
                    <button
                        type="button"
                        onClick={onBack}
                        disabled={isSubmitting}
                        className="px-3 py-2.5 rounded-lg bg-content/10 text-content text-sm font-medium hover:bg-content/20 disabled:opacity-50 transition"
                    >
                        Back
                    </button>
                    <button
                        type="button"
                        onClick={onValidate}
                        disabled={isSubmitting}
                        className="px-3 py-2.5 rounded-lg bg-emerald-600 text-white text-sm font-semibold hover:bg-emerald-500 disabled:opacity-50 disabled:cursor-not-allowed transition flex items-center justify-center gap-2"
                    >
                        {isSubmitting ? (
                            <><Loader2 className="w-4 h-4 animate-spin" /> Processing…</>
                        ) : (
                            isDelivery ? 'Validate & Queue' : 'Validate & Print'
                        )}
                    </button>
                </footer>
            </div>
        </div>
    );
}

function Section({ title, children }) {
    return (
        <div>
            <h3 className="text-[11px] font-semibold uppercase tracking-wider text-content-muted mb-1.5">{title}</h3>
            <div className="space-y-1">{children}</div>
        </div>
    );
}

function Meta({ label, value }) {
    return (
        <div className="flex items-center justify-between text-xs">
            <span className="text-content-muted">{label}</span>
            <span className="text-content truncate ml-3">{value}</span>
        </div>
    );
}

function Line({ label, value, tone }) {
    const toneClass = tone === 'red' ? 'text-red-600 dark:text-red-400'
        : tone === 'emerald' ? 'text-emerald-600 dark:text-emerald-400'
        : 'text-content';
    return (
        <div className="flex items-center justify-between">
            <span className="text-content-muted">{label}</span>
            <span className={`tabular-nums ${toneClass}`}>{value}</span>
        </div>
    );
}
