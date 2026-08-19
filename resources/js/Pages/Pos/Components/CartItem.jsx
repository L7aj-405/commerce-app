export default function CartItem({ row, currency = '$', onUpdateQuantity, onUpdateDiscount, onRemove }) {
    const lineSubtotal = row.unit_price * row.quantity;
    const lineDiscount = lineSubtotal * (row.discount_percent / 100);
    const lineTotal = lineSubtotal - lineDiscount;

    return (
        <div className="py-3">
            <div className="flex items-start justify-between gap-2">
                <div className="min-w-0">
                    <div className="text-sm font-semibold text-content truncate">{row.product_name}</div>
                    {row.variant_name && (
                        <div className="mt-0.5 inline-flex items-center px-1.5 py-0.5 rounded bg-indigo-500/10 text-indigo-700 dark:text-indigo-300 text-[11px] font-medium max-w-full truncate">
                            {row.variant_name}
                        </div>
                    )}
                    <div className="text-xs text-content-muted font-mono truncate">{row.product_sku}</div>
                </div>
                <button
                    type="button"
                    onClick={() => onRemove(row.line_id)}
                    aria-label={`Remove ${row.product_name}`}
                    className="p-1 text-content-muted hover:text-red-500 dark:hover:text-red-400 transition"
                >
                    <svg className="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24" strokeWidth="2">
                        <path strokeLinecap="round" strokeLinejoin="round" d="M6 18L18 6M6 6l12 12" />
                    </svg>
                </button>
            </div>

            <div className="mt-2 flex items-center justify-between gap-2">
                <div className="flex items-center gap-1">
                    <button
                        type="button"
                        onClick={() => onUpdateQuantity(row.line_id, row.quantity - 1)}
                        aria-label="Decrease quantity"
                        className="w-7 h-7 rounded-md bg-content/10 text-content hover:bg-content/20 transition"
                    >
                        −
                    </button>
                    <input
                        type="number"
                        min="1"
                        value={row.quantity}
                        onChange={(e) => onUpdateQuantity(row.line_id, Number(e.target.value))}
                        aria-label="Quantity"
                        className="w-12 h-7 text-center text-sm rounded-md border border-line bg-surface-3 text-content focus:outline-none focus:ring-2 focus:ring-indigo-500"
                    />
                    <button
                        type="button"
                        onClick={() => onUpdateQuantity(row.line_id, row.quantity + 1)}
                        aria-label="Increase quantity"
                        className="w-7 h-7 rounded-md bg-content/10 text-content hover:bg-content/20 transition"
                    >
                        +
                    </button>
                </div>

                <div className="text-xs text-content-muted tabular-nums">
                    {row.quantity} × {currency}
                    {Number(row.unit_price).toFixed(2)}
                </div>
            </div>

            <div className="mt-2 flex items-center justify-between gap-2">
                <label className="flex items-center gap-1 text-[11px] text-content-muted">
                    <span>Discount</span>
                    <input
                        type="number"
                        min="0"
                        max="100"
                        value={row.discount_percent}
                        onChange={(e) => onUpdateDiscount(row.line_id, Number(e.target.value))}
                        aria-label="Item discount percent"
                        className="w-14 h-6 px-1 text-center text-xs rounded-md border border-line bg-surface-3 text-content focus:outline-none focus:ring-2 focus:ring-indigo-500"
                    />
                    <span>%</span>
                </label>
                <div className="text-sm font-semibold text-content tabular-nums">
                    {currency}
                    {lineTotal.toFixed(2)}
                </div>
            </div>
        </div>
    );
}
