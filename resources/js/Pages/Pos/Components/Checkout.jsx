import { useEffect } from 'react';
import { Zap, Truck } from 'lucide-react';

const FULFILLMENT_TYPES = [
    { value: 'instant',  label: 'Instant pickup',  hint: 'Customer takes it now', icon: Zap },
    { value: 'delivery', label: 'Delivery / later', hint: 'Prepare, pack, ship',   icon: Truck },
];

// Quick fees for common delivery zones / distances; the input still allows any value.
const DELIVERY_PRESETS = [10, 20, 30, 50];

const PAYMENT_METHODS = [
    { value: 'cash', label: 'Cash' },
    { value: 'card', label: 'Card' },
    { value: 'check', label: 'Check' },
    { value: 'mobile', label: 'Mobile' },
    { value: 'mixed', label: 'Mixed' },
];

const fieldClass =
    'mt-1 w-full px-3 py-2 rounded-lg border border-line bg-surface-3 text-content focus:outline-none focus:ring-2 focus:ring-indigo-500';

export default function Checkout({ cart, currency = '$', isSubmitting, error, onConfirm, onCancel }) {
    useEffect(() => {
        if (cart.amountPaid === 0 && cart.total > 0) {
            cart.setAmountPaid(cart.total);
        }
    }, [cart.total]); // eslint-disable-line react-hooks/exhaustive-deps

    const isDelivery      = cart.fulfillmentType === 'delivery';
    const insufficient    = cart.amountPaid < cart.total;
    const companyMissing  = cart.customerType === 'company' && ! cart.companyName.trim();
    const deliveryMissing = isDelivery && ! cart.deliveryAddress.trim();

    return (
        <div className="flex flex-col gap-3 text-sm">
            {error && (
                <div className="rounded-md bg-red-500/10 border border-red-500/30 px-3 py-2 text-red-700 dark:text-red-300 text-xs">
                    {error}
                </div>
            )}

            {/* Fulfillment method — instant completes the order now, delivery queues it for the warehouse. */}
            <div className="grid grid-cols-2 gap-2">
                {FULFILLMENT_TYPES.map((opt) => {
                    const active = cart.fulfillmentType === opt.value;
                    const Icon = opt.icon;
                    return (
                        <button
                            key={opt.value}
                            type="button"
                            onClick={() => cart.setFulfillment({ fulfillmentType: opt.value })}
                            className={`flex items-start gap-2 px-3 py-2 rounded-lg border text-left transition ${
                                active
                                    ? 'border-indigo-500 bg-indigo-500/10 text-content'
                                    : 'border-line bg-surface-3 text-content-muted hover:text-content'
                            }`}
                        >
                            <Icon className={`w-4 h-4 mt-0.5 shrink-0 ${active ? 'text-indigo-500' : ''}`} />
                            <span className="flex flex-col">
                                <span className="text-xs font-semibold">{opt.label}</span>
                                <span className="text-[10px] text-content-muted">{opt.hint}</span>
                            </span>
                        </button>
                    );
                })}
            </div>

            {/* Delivery details — expands smoothly only for delivery orders. */}
            <div className={`grid transition-all duration-300 ease-out ${isDelivery ? 'grid-rows-[1fr] opacity-100' : 'grid-rows-[0fr] opacity-0'}`}>
                <div className="overflow-hidden">
                    <div className="flex flex-col gap-3 pt-0.5">
                        <label className="block">
                            <span className="text-xs text-content-muted">Delivery address <span className="text-red-500">*</span></span>
                            <textarea
                                rows={2}
                                value={cart.deliveryAddress}
                                onChange={(e) => cart.setFulfillment({ deliveryAddress: e.target.value })}
                                placeholder="Where the order ships / is picked up"
                                className={`${fieldClass} text-xs placeholder:text-content-muted ${deliveryMissing ? 'border-red-500/60' : ''}`}
                            />
                        </label>

                        {/* Shipping / delivery fee */}
                        <div className="rounded-lg border border-line bg-surface-3 p-3">
                            <div className="flex items-center justify-between gap-2">
                                <span className="text-xs font-semibold text-content">Shipping / delivery fee</span>
                                <button
                                    type="button"
                                    role="switch"
                                    aria-checked={cart.freeDelivery}
                                    onClick={() => cart.setDelivery({ freeDelivery: ! cart.freeDelivery })}
                                    className="inline-flex items-center gap-2 text-[11px] font-medium text-content-muted"
                                >
                                    <span className={`relative w-9 h-5 rounded-full transition-colors ${cart.freeDelivery ? 'bg-emerald-500' : 'bg-content/20'}`}>
                                        <span className={`absolute top-0.5 left-0.5 w-4 h-4 rounded-full bg-white shadow transition-transform ${cart.freeDelivery ? 'translate-x-4' : ''}`} />
                                    </span>
                                    Free shipping
                                </button>
                            </div>

                            <div className={`relative mt-2 transition-opacity ${cart.freeDelivery ? 'opacity-50' : ''}`}>
                                <span className="absolute left-3 top-1/2 -translate-y-1/2 text-xs text-content-muted">{currency}</span>
                                <input
                                    type="number"
                                    min="0"
                                    step="0.01"
                                    inputMode="decimal"
                                    disabled={cart.freeDelivery}
                                    value={cart.freeDelivery ? '' : (cart.deliveryFee || '')}
                                    onChange={(e) => cart.setDelivery({ deliveryFee: e.target.value })}
                                    placeholder={cart.freeDelivery ? 'Free' : '0.00'}
                                    className={`w-full pl-7 pr-3 py-2 rounded-lg border border-line bg-surface text-content tabular-nums text-sm focus:outline-none focus:ring-2 focus:ring-indigo-500 disabled:cursor-not-allowed`}
                                />
                            </div>

                            {/* Zone / distance presets */}
                            {! cart.freeDelivery && (
                                <div className="mt-2 flex flex-wrap gap-1.5">
                                    {DELIVERY_PRESETS.map((amount) => {
                                        const active = Number(cart.deliveryFee) === amount;
                                        return (
                                            <button
                                                key={amount}
                                                type="button"
                                                onClick={() => cart.setDelivery({ deliveryFee: amount, freeDelivery: false })}
                                                className={`px-2.5 py-1 rounded-md text-[11px] font-medium tabular-nums transition ${
                                                    active
                                                        ? 'bg-indigo-500 text-white'
                                                        : 'bg-surface border border-line text-content-muted hover:text-content'
                                                }`}
                                            >
                                                {currency}{amount}
                                            </button>
                                        );
                                    })}
                                </div>
                            )}
                        </div>
                    </div>
                </div>
            </div>

            <label className="block">
                <span className="text-xs text-content-muted">Payment method</span>
                <select
                    value={cart.paymentMethod}
                    onChange={(e) => cart.setPaymentMethod(e.target.value)}
                    className={fieldClass}
                >
                    {PAYMENT_METHODS.map((m) => (
                        <option key={m.value} value={m.value}>
                            {m.label}
                        </option>
                    ))}
                </select>
            </label>

            <label className="block">
                <span className="text-xs text-content-muted">Amount paid</span>
                <input
                    type="number"
                    min="0"
                    step="0.01"
                    value={cart.amountPaid}
                    onChange={(e) => cart.setAmountPaid(e.target.value)}
                    className={`${fieldClass} tabular-nums`}
                />
            </label>

            <div className={`flex justify-between font-semibold ${insufficient ? 'text-red-600 dark:text-red-400' : 'text-emerald-600 dark:text-emerald-400'}`}>
                <span>{insufficient ? 'Short' : 'Change'}</span>
                <span className="tabular-nums">
                    {currency}
                    {(insufficient ? cart.total - cart.amountPaid : cart.change).toFixed(2)}
                </span>
            </div>

            <div className="pt-3 border-t border-line space-y-2">
                {/* Customer type — decides the print format at validation time. */}
                <div className="grid grid-cols-2 gap-2">
                    {CUSTOMER_TYPES.map((opt) => {
                        const active = cart.customerType === opt.value;
                        return (
                            <button
                                key={opt.value}
                                type="button"
                                onClick={() => cart.setCustomer({ customerType: opt.value })}
                                className={`flex flex-col items-start px-3 py-2 rounded-lg border text-left transition ${
                                    active
                                        ? 'border-indigo-500 bg-indigo-500/10 text-content'
                                        : 'border-line bg-surface-3 text-content-muted hover:text-content'
                                }`}
                            >
                                <span className="text-xs font-semibold">{opt.label}</span>
                                <span className="text-[10px] text-content-muted">{opt.hint}</span>
                            </button>
                        );
                    })}
                </div>

                <input
                    type="text"
                    value={cart.customerName}
                    onChange={(e) => cart.setCustomer({ customerName: e.target.value })}
                    placeholder={cart.customerType === 'company' ? 'Contact name (optional)' : 'Customer name (optional)'}
                    className={`${fieldClass} mt-0 text-xs placeholder:text-content-muted`}
                />

                {cart.customerType === 'company' && (
                    <>
                        <input
                            type="text"
                            value={cart.companyName}
                            onChange={(e) => cart.setCustomer({ companyName: e.target.value })}
                            placeholder="Company name (required)"
                            className={`${fieldClass} mt-0 text-xs placeholder:text-content-muted ${companyMissing ? 'border-red-500/60' : ''}`}
                        />
                        <input
                            type="text"
                            value={cart.taxId}
                            onChange={(e) => cart.setCustomer({ taxId: e.target.value })}
                            placeholder="Tax ID / ICE (optional)"
                            className={`${fieldClass} mt-0 text-xs placeholder:text-content-muted`}
                        />
                    </>
                )}

                <input
                    type="tel"
                    value={cart.customerPhone}
                    onChange={(e) => cart.setCustomer({ customerPhone: e.target.value })}
                    placeholder="Phone (optional)"
                    className={`${fieldClass} mt-0 text-xs placeholder:text-content-muted`}
                />
                <input
                    type="email"
                    value={cart.customerEmail}
                    onChange={(e) => cart.setCustomer({ customerEmail: e.target.value })}
                    placeholder="Email (optional)"
                    className={`${fieldClass} mt-0 text-xs placeholder:text-content-muted`}
                />
                <textarea
                    rows={2}
                    value={cart.notes}
                    onChange={(e) => cart.setNotes(e.target.value)}
                    placeholder="Notes (optional)"
                    className={`${fieldClass} mt-0 text-xs placeholder:text-content-muted`}
                />
            </div>

            {companyMissing && (
                <p className="text-[11px] text-red-600 dark:text-red-400">Company name is required to issue an A4 invoice.</p>
            )}
            {deliveryMissing && (
                <p className="text-[11px] text-red-600 dark:text-red-400">A delivery address is required for delivery orders.</p>
            )}

            <div className="grid grid-cols-2 gap-2 pt-2">
                <button
                    type="button"
                    onClick={onCancel}
                    disabled={isSubmitting}
                    className="px-3 py-2 rounded-lg bg-content/10 text-content text-sm font-medium hover:bg-content/20 disabled:opacity-50 transition"
                >
                    Cancel
                </button>
                <button
                    type="button"
                    onClick={onConfirm}
                    disabled={isSubmitting || insufficient || companyMissing || deliveryMissing}
                    className="px-3 py-2 rounded-lg bg-emerald-600 text-white text-sm font-semibold hover:bg-emerald-500 disabled:opacity-50 disabled:cursor-not-allowed transition flex items-center justify-center gap-2"
                >
                    Review order →
                </button>
            </div>
        </div>
    );
}

const CUSTOMER_TYPES = [
    { value: 'individual', label: 'Individual', hint: 'Thermal receipt' },
    { value: 'company',    label: 'Company',    hint: 'A4 invoice' },
];
