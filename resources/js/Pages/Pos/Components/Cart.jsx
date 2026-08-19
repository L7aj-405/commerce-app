import { useState } from 'react';
import CartItem from './CartItem';
import Checkout from './Checkout';
import CheckoutPreviewModal from './CheckoutPreviewModal';

export default function Cart({ cart, currency = '$', onSubmit, isSubmitting, error }) {
    const [mode, setMode] = useState('normal');
    const [showPreview, setShowPreview] = useState(false);

    const lineRows = cart.items;

    const goToCheckout = () => setMode('checkout');
    const backToCart   = () => setMode('normal');

    // Committing the sale: only fired from the preview modal's "Validate & Print".
    const handleConfirm = async () => {
        const ok = await onSubmit();
        if (ok) {
            setShowPreview(false);
            setMode('normal');
        }
    };

    return (
        <aside className="flex flex-col w-full lg:w-96 lg:h-full min-h-0 max-h-[55vh] lg:max-h-none bg-surface-3 border-t lg:border-t-0 lg:border-l border-line shadow-xl">
            <header className="flex-shrink-0 px-4 py-3 border-b border-line flex items-center justify-between">
                <h2 className="font-semibold text-content">
                    Cart <span className="text-content-muted text-sm">({cart.itemCount})</span>
                </h2>
                {mode === 'checkout' && (
                    <button
                        type="button"
                        onClick={backToCart}
                        className="text-xs text-content-muted hover:text-content"
                    >
                        ← Back
                    </button>
                )}
            </header>

            {/* Scrollable content — everything that can overflow lives here so
                the bottom elements are never pushed past the panel edge. */}
            <div className="flex-1 min-h-0 overflow-y-auto">
                {mode === 'normal' ? (
                    <div className="px-4 divide-y divide-line/60">
                        {lineRows.length === 0 ? (
                            <div className="flex flex-col items-center justify-center py-12 text-content-muted">
                                <svg className="w-10 h-10 mb-2" fill="none" stroke="currentColor" viewBox="0 0 24 24" strokeWidth="1.5">
                                    <path strokeLinecap="round" strokeLinejoin="round" d="M3 3h2l.4 2M7 13h10l4-8H5.4M7 13L5.4 5M7 13l-2.293 2.293c-.63.63-.184 1.707.707 1.707H17m0 0a2 2 0 100 4 2 2 0 000-4zm-8 2a2 2 0 11-4 0 2 2 0 014 0z" />
                                </svg>
                                <p className="text-sm">Cart is empty</p>
                                <p className="text-xs text-content-muted/60 mt-1">Tap a product to add it.</p>
                            </div>
                        ) : (
                            lineRows.map((row) => (
                                <CartItem
                                    key={row.line_id}
                                    row={row}
                                    currency={currency}
                                    onUpdateQuantity={cart.updateQuantity}
                                    onUpdateDiscount={cart.updateItemDiscount}
                                    onRemove={cart.removeItem}
                                />
                            ))
                        )}
                    </div>
                ) : (
                    <div className="px-4 py-3 space-y-3">
                        <Summary cart={cart} currency={currency} />
                        <Checkout
                            cart={cart}
                            currency={currency}
                            isSubmitting={isSubmitting}
                            error={error}
                            onConfirm={() => setShowPreview(true)}
                            onCancel={backToCart}
                        />
                    </div>
                )}
            </div>

            {/* Pinned footer — normal mode only: totals recap + primary actions.
                In checkout mode the form (and its Cancel / Review buttons) scrolls
                in the area above, so nothing is cut off. */}
            {mode === 'normal' && (
                <footer className="flex-shrink-0 border-t border-line p-4 space-y-3">
                    <Summary cart={cart} currency={currency} />
                    <div className="grid grid-cols-2 gap-2">
                        <button
                            type="button"
                            onClick={cart.clearCart}
                            disabled={lineRows.length === 0}
                            className="px-3 py-2 rounded-lg bg-content/10 text-content text-sm font-medium hover:bg-content/20 disabled:opacity-50 transition"
                        >
                            Clear cart
                        </button>
                        <button
                            type="button"
                            onClick={goToCheckout}
                            disabled={lineRows.length === 0}
                            className="px-3 py-2 rounded-lg bg-emerald-600 text-white text-sm font-semibold hover:bg-emerald-500 disabled:opacity-50 disabled:cursor-not-allowed transition"
                        >
                            Checkout
                        </button>
                    </div>
                </footer>
            )}

            {showPreview && (
                <CheckoutPreviewModal
                    cart={cart}
                    currency={currency}
                    isSubmitting={isSubmitting}
                    error={error}
                    onBack={() => setShowPreview(false)}
                    onValidate={handleConfirm}
                />
            )}
        </aside>
    );
}

function Summary({ cart, currency }) {
    return (
        <dl className="text-sm text-content-muted space-y-1 tabular-nums">
            <div className="flex justify-between">
                <dt>Subtotal</dt>
                <dd>
                    {currency}
                    {cart.subtotal.toFixed(2)}
                </dd>
            </div>

            <div className="flex items-center justify-between text-xs">
                <dt className="flex items-center gap-1">
                    Discount
                    <input
                        type="number"
                        min="0"
                        max="100"
                        value={cart.discountPercent}
                        onChange={(e) => cart.setGlobalDiscount(Number(e.target.value))}
                        aria-label="Global discount percent"
                        className="w-14 h-6 px-1 text-center rounded-md border border-line bg-surface-2 text-content focus:outline-none focus:ring-2 focus:ring-indigo-500"
                    />
                    %
                </dt>
                <dd className="text-red-700 dark:text-red-300">
                    −{currency}
                    {cart.discountAmount.toFixed(2)}
                </dd>
            </div>

            {cart.taxRate > 0 && (
                <div className="flex justify-between text-xs">
                    <dt>Tax ({(cart.taxRate * 100).toFixed(2)}%)</dt>
                    <dd>
                        {currency}
                        {cart.taxAmount.toFixed(2)}
                    </dd>
                </div>
            )}

            <div className="flex justify-between pt-2 mt-2 border-t border-line/60 text-base font-bold text-content">
                <dt>Total</dt>
                <dd>
                    {currency}
                    {cart.total.toFixed(2)}
                </dd>
            </div>
        </dl>
    );
}
