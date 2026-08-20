import { useState } from 'react';
import { router } from '@inertiajs/react';
import { X, Loader2 } from 'lucide-react';

const TYPES = [
    { value: 'set_on_hand', label: 'Set on hand' },
    { value: 'increase', label: 'Increase' },
    { value: 'decrease', label: 'Decrease' },
];

/**
 * Inventory-safe stock adjustment for the Product Edit page. Posts to
 * POST /dashboard/products/{product}/stock, which goes through
 * CatalogInventoryService + InventoryEngine (ledger entry, WarehouseInventoryBalance)
 * and then synchronously pushes the new quantity to WooCommerce — never
 * a direct product.quantity write.
 */
export default function AdjustStockModal({ product, variantId, variantName, warehouses = [], onClose, onAdjusted }) {
    // Never guess when more than one warehouse exists — the field starts
    // empty (submit stays disabled) and the user must pick explicitly. A
    // single warehouse is the only case safe to pre-select.
    const [warehouseId, setWarehouseId] = useState(warehouses.length === 1 ? warehouses[0].id : '');
    const [type, setType] = useState('set_on_hand');
    const [quantity, setQuantity] = useState('');
    const [reason, setReason] = useState('');
    const [processing, setProcessing] = useState(false);
    const [errors, setErrors] = useState({});

    const submit = (e) => {
        e.preventDefault();
        if (!warehouseId) return;
        setProcessing(true);
        setErrors({});

        router.post(`/dashboard/products/${product.id}/stock`, {
            warehouse_id: warehouseId,
            type,
            quantity: parseInt(quantity, 10) || 0,
            reason,
            variant_id: variantId ?? undefined,
        }, {
            preserveScroll: true,
            preserveState: true,
            onSuccess: () => {
                // Refresh only the `product` prop (fresh stock numbers) —
                // never a full browser reload, and never touches any
                // unsaved edits sitting in the product edit form.
                onAdjusted?.();
                onClose();
            },
            onError: (errs) => setErrors(errs),
            onFinish: () => setProcessing(false),
        });
    };

    return (
        <div className="fixed inset-0 bg-black/60 backdrop-blur-sm flex items-center justify-center z-50 p-4">
            <div className="bg-surface-2 border border-line rounded-xl shadow-xl max-w-sm w-full overflow-hidden text-left">
                <div className="p-4 border-b border-line flex justify-between items-center bg-surface">
                    <h3 className="font-semibold text-content text-sm">
                        Adjust stock{variantName ? ` — ${variantName}` : ''}
                    </h3>
                    <button type="button" onClick={onClose} className="text-content-muted hover:text-content">
                        <X className="w-4 h-4" />
                    </button>
                </div>

                <form onSubmit={submit} className="p-4 space-y-4">
                    <div>
                        <label className="block text-xs font-medium text-content-muted mb-1">Warehouse</label>
                        <select
                            value={warehouseId}
                            onChange={(e) => setWarehouseId(e.target.value)}
                            className="w-full px-3 py-2 rounded-lg bg-surface-3 border border-line text-content text-sm focus:outline-none focus:ring-2 focus:ring-indigo-500"
                            required
                        >
                            {(warehouses.length === 0 || warehouses.length > 1) && (
                                <option value="">{warehouses.length === 0 ? 'No warehouses' : 'Choose a warehouse…'}</option>
                            )}
                            {warehouses.map((w) => (
                                <option key={w.id} value={w.id}>{w.name}</option>
                            ))}
                        </select>
                        {warehouses.length > 1 && !warehouseId && (
                            <p className="mt-1 text-xs text-amber-600 dark:text-amber-400">Multiple warehouses exist — pick one before adjusting.</p>
                        )}
                        {errors.warehouse_id && <p className="mt-1 text-xs text-red-600 dark:text-red-400">{errors.warehouse_id}</p>}
                    </div>

                    <div>
                        <label className="block text-xs font-medium text-content-muted mb-1">Adjustment type</label>
                        <div className="grid grid-cols-3 gap-2">
                            {TYPES.map((t) => (
                                <button
                                    key={t.value}
                                    type="button"
                                    onClick={() => setType(t.value)}
                                    className={`px-2 py-1.5 rounded-lg text-xs font-medium border transition ${
                                        type === t.value
                                            ? 'border-indigo-500 bg-indigo-500/10 text-indigo-600 dark:text-indigo-400'
                                            : 'border-line bg-surface-3 text-content-muted hover:bg-content/10'
                                    }`}
                                >
                                    {t.label}
                                </button>
                            ))}
                        </div>
                    </div>

                    <div>
                        <label className="block text-xs font-medium text-content-muted mb-1">
                            Quantity {type === 'set_on_hand' ? '(new total)' : '(amount)'}
                        </label>
                        <input
                            type="number"
                            min={type === 'set_on_hand' ? 0 : 1}
                            value={quantity}
                            onChange={(e) => setQuantity(e.target.value)}
                            className="w-full px-3 py-2 rounded-lg bg-surface-3 border border-line text-content text-sm focus:outline-none focus:ring-2 focus:ring-indigo-500"
                            required
                        />
                        {errors.quantity && <p className="mt-1 text-xs text-red-600 dark:text-red-400">{errors.quantity}</p>}
                        {errors.stock && <p className="mt-1 text-xs text-red-600 dark:text-red-400">{errors.stock}</p>}
                    </div>

                    <div>
                        <label className="block text-xs font-medium text-content-muted mb-1">Reason</label>
                        <input
                            type="text"
                            value={reason}
                            onChange={(e) => setReason(e.target.value)}
                            placeholder="e.g. Physical recount, damaged goods, restock…"
                            className="w-full px-3 py-2 rounded-lg bg-surface-3 border border-line text-content text-sm focus:outline-none focus:ring-2 focus:ring-indigo-500"
                            required
                        />
                        {errors.reason && <p className="mt-1 text-xs text-red-600 dark:text-red-400">{errors.reason}</p>}
                    </div>

                    <button
                        type="submit"
                        disabled={processing || !warehouseId}
                        className="w-full inline-flex items-center justify-center gap-1.5 px-4 py-2 text-sm font-semibold rounded-lg bg-indigo-600 text-white hover:bg-indigo-500 disabled:opacity-50"
                    >
                        {processing && <Loader2 className="w-4 h-4 animate-spin" />}
                        Save adjustment
                    </button>
                </form>
            </div>
        </div>
    );
}
