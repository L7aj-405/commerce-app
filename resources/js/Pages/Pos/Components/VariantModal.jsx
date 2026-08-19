import { useEffect, useMemo, useState } from 'react';
import { X, Plus, Minus, Check, ImageOff, ShoppingCart } from 'lucide-react';

/**
 * Variant picker for a variable product. The cashier chooses one value per
 * attribute (Size, Color, …); the modal resolves the exact matching variant,
 * shows its live stock, disables combinations that can't be sold, and hands the
 * chosen variant + quantity back to the cart on "Add to cart".
 *
 * Availability is computed relative to the *other* current selections, so e.g.
 * once "Red" is picked, only sizes that exist in Red (and are in stock) stay
 * enabled — the classic e-commerce swatch behaviour.
 */
export default function VariantModal({ product, currency = '$', onAdd, onClose }) {
    const attributes = product.attributes ?? [];
    const variants   = product.variants ?? [];

    // Pre-select any attribute that has only one possible value — nothing to choose.
    const [selected, setSelected] = useState(() => {
        const init = {};
        for (const attr of attributes) {
            if (attr.values.length === 1) init[attr.name] = attr.values[0];
        }
        return init;
    });
    const [quantity, setQuantity] = useState(1);

    // The variant matching every selected attribute (only once all are chosen).
    const resolved = useMemo(() => {
        const allChosen = attributes.every((a) => selected[a.name] != null);
        if (! allChosen) return null;
        return variants.find((v) =>
            attributes.every((a) => v.options?.[a.name] === selected[a.name]),
        ) ?? null;
    }, [attributes, variants, selected]);

    // Best stock reachable for a given attribute value, holding the *other*
    // selections fixed. null → combination doesn't exist; 0 → exists but sold out.
    const stockForValue = useMemo(() => (attrName, value) => {
        const matching = variants.filter((v) => {
            if (v.options?.[attrName] !== value) return false;
            return attributes.every(
                (a) => a.name === attrName || selected[a.name] == null || v.options?.[a.name] === selected[a.name],
            );
        });
        if (matching.length === 0) return null;
        return Math.max(...matching.map((v) => v.stock ?? 0));
    }, [variants, attributes, selected]);

    const maxQty      = resolved ? Math.max(0, resolved.stock ?? 0) : 0;
    const canAdd      = resolved != null && maxQty > 0 && quantity >= 1 && quantity <= maxQty;
    const displayImg  = resolved?.image ?? firstImage(product.images);
    const displayPrice = resolved ? resolved.price : (product.price_from ?? product.price);

    // Keep quantity within the resolved variant's stock as selections change.
    useEffect(() => {
        setQuantity((q) => Math.min(Math.max(1, q), Math.max(1, maxQty)));
    }, [maxQty]);

    // Close on Escape.
    useEffect(() => {
        const onKey = (e) => e.key === 'Escape' && onClose();
        window.addEventListener('keydown', onKey);
        return () => window.removeEventListener('keydown', onKey);
    }, [onClose]);

    const pick = (attrName, value) =>
        setSelected((prev) => ({ ...prev, [attrName]: prev[attrName] === value ? undefined : value }));

    const handleAdd = () => {
        if (! canAdd) return;
        onAdd(product, { variant: resolved, quantity });
        onClose();
    };

    return (
        <div className="fixed inset-0 z-50 flex items-center justify-center p-4 no-print">
            <div className="absolute inset-0 bg-black/50 backdrop-blur-sm" onClick={onClose} />

            <div className="relative w-full max-w-lg max-h-[90vh] flex flex-col bg-surface border border-line rounded-2xl shadow-2xl text-content">
                {/* Header */}
                <header className="flex items-start justify-between gap-3 px-5 py-4 border-b border-line">
                    <div className="flex items-center gap-3 min-w-0">
                        <div className="w-14 h-14 rounded-xl overflow-hidden bg-surface-2 flex-shrink-0">
                            {displayImg ? (
                                <img src={displayImg} alt={product.name} className="w-full h-full object-cover" />
                            ) : (
                                <div className="w-full h-full flex items-center justify-center text-content-muted/60">
                                    <ImageOff className="w-6 h-6" />
                                </div>
                            )}
                        </div>
                        <div className="min-w-0">
                            <h2 className="text-base font-semibold truncate">{product.name}</h2>
                            <p className="text-xs text-content-muted font-mono truncate">
                                {resolved?.sku ?? product.sku}
                            </p>
                            <p className="text-sm font-bold text-content mt-0.5 tabular-nums">
                                {! resolved && (product.price_from != null) && (
                                    <span className="text-xs font-medium text-content-muted mr-1">from</span>
                                )}
                                {currency}{Number(displayPrice).toFixed(2)}
                            </p>
                        </div>
                    </div>
                    <button
                        type="button"
                        onClick={onClose}
                        aria-label="Close"
                        className="p-1.5 rounded-lg text-content-muted hover:text-content hover:bg-surface-2 transition"
                    >
                        <X className="w-5 h-5" />
                    </button>
                </header>

                {/* Attribute selectors */}
                <div className="flex-1 overflow-y-auto px-5 py-4 space-y-5">
                    {attributes.map((attr) => (
                        <div key={attr.name}>
                            <div className="flex items-center justify-between mb-2">
                                <h3 className="text-[11px] font-semibold uppercase tracking-wider text-content-muted">
                                    {attr.name}
                                </h3>
                                {selected[attr.name] && (
                                    <span className="text-xs text-content-muted">
                                        {selected[attr.name]}
                                    </span>
                                )}
                            </div>
                            <div className="flex flex-wrap gap-2">
                                {attr.values.map((value) => {
                                    const isActive  = selected[attr.name] === value;
                                    const stock     = stockForValue(attr.name, value);
                                    const disabled  = stock === null || stock === 0;
                                    return (
                                        <button
                                            key={value}
                                            type="button"
                                            disabled={disabled}
                                            onClick={() => pick(attr.name, value)}
                                            aria-pressed={isActive}
                                            className={[
                                                'relative px-3.5 py-2 rounded-lg text-sm font-medium border transition select-none',
                                                isActive
                                                    ? 'border-indigo-500 bg-indigo-500/10 text-indigo-700 dark:text-indigo-300 ring-1 ring-indigo-500'
                                                    : disabled
                                                        ? 'border-line bg-surface-2 text-content-muted/50 cursor-not-allowed line-through'
                                                        : 'border-line bg-surface-2 text-content hover:border-indigo-400 hover:bg-surface-3',
                                            ].join(' ')}
                                        >
                                            {value}
                                            {isActive && (
                                                <Check className="inline-block w-3.5 h-3.5 ml-1 -mt-0.5" strokeWidth={3} />
                                            )}
                                        </button>
                                    );
                                })}
                            </div>
                        </div>
                    ))}

                    {/* Live availability for the resolved combination */}
                    <StockLine resolved={resolved} attributes={attributes} selected={selected} />
                </div>

                {/* Footer — quantity + add */}
                <footer className="px-5 py-4 border-t border-line space-y-3">
                    <div className="flex items-center justify-between">
                        <span className="text-sm text-content-muted">Quantity</span>
                        <div className="flex items-center gap-1">
                            <button
                                type="button"
                                onClick={() => setQuantity((q) => Math.max(1, q - 1))}
                                disabled={! resolved || quantity <= 1}
                                aria-label="Decrease quantity"
                                className="w-8 h-8 rounded-md bg-content/10 text-content hover:bg-content/20 disabled:opacity-40 transition flex items-center justify-center"
                            >
                                <Minus className="w-4 h-4" />
                            </button>
                            <input
                                type="number"
                                min="1"
                                max={maxQty || 1}
                                value={quantity}
                                onChange={(e) => setQuantity(clampQty(e.target.value, maxQty))}
                                disabled={! resolved}
                                aria-label="Quantity"
                                className="w-14 h-8 text-center text-sm rounded-md border border-line bg-surface-2 text-content focus:outline-none focus:ring-2 focus:ring-indigo-500 disabled:opacity-40"
                            />
                            <button
                                type="button"
                                onClick={() => setQuantity((q) => Math.min(maxQty || 1, q + 1))}
                                disabled={! resolved || quantity >= maxQty}
                                aria-label="Increase quantity"
                                className="w-8 h-8 rounded-md bg-content/10 text-content hover:bg-content/20 disabled:opacity-40 transition flex items-center justify-center"
                            >
                                <Plus className="w-4 h-4" />
                            </button>
                        </div>
                    </div>

                    <button
                        type="button"
                        onClick={handleAdd}
                        disabled={! canAdd}
                        className="w-full flex items-center justify-center gap-2 px-4 py-3 rounded-xl bg-indigo-600 text-white text-sm font-semibold hover:bg-indigo-500 disabled:opacity-50 disabled:cursor-not-allowed transition"
                    >
                        <ShoppingCart className="w-4 h-4" />
                        {addButtonLabel(resolved, attributes, selected, maxQty, quantity, currency)}
                    </button>
                </footer>
            </div>
        </div>
    );
}

// Availability banner beneath the selectors.
function StockLine({ resolved, attributes, selected }) {
    const allChosen = attributes.every((a) => selected[a.name] != null);

    if (! allChosen) {
        return (
            <p className="text-xs text-content-muted">
                Select {attributes.filter((a) => selected[a.name] == null).map((a) => a.name).join(' & ')} to continue.
            </p>
        );
    }

    if (! resolved) {
        return (
            <div className="rounded-lg bg-amber-500/10 border border-amber-500/30 px-3 py-2 text-xs text-amber-700 dark:text-amber-300">
                This combination isn’t available.
            </div>
        );
    }

    const stock = resolved.stock ?? 0;
    if (stock <= 0) {
        return (
            <div className="rounded-lg bg-red-500/10 border border-red-500/30 px-3 py-2 text-xs font-medium text-red-700 dark:text-red-300">
                Out of stock
            </div>
        );
    }

    const low = stock <= 5;
    return (
        <div className={`rounded-lg px-3 py-2 text-xs font-medium border ${
            low
                ? 'bg-amber-500/10 border-amber-500/30 text-amber-700 dark:text-amber-300'
                : 'bg-emerald-500/10 border-emerald-500/30 text-emerald-700 dark:text-emerald-300'
        }`}>
            In stock — {stock} left{low ? ' (low)' : ''}
        </div>
    );
}

function addButtonLabel(resolved, attributes, selected, maxQty, quantity, currency) {
    const allChosen = attributes.every((a) => selected[a.name] != null);
    if (! allChosen) return 'Choose options';
    if (! resolved) return 'Unavailable';
    if (maxQty <= 0) return 'Out of stock';
    const total = (resolved.price ?? 0) * quantity;
    return `Add to cart · ${currency}${total.toFixed(2)}`;
}

function clampQty(raw, max) {
    const n = Math.floor(Number(raw) || 1);
    if (n < 1) return 1;
    if (max > 0 && n > max) return max;
    return n;
}

function firstImage(imageField) {
    try {
        if (Array.isArray(imageField)) return imageField[0] || null;
        if (typeof imageField === 'string' && imageField.startsWith('[')) {
            return JSON.parse(imageField)[0] || null;
        }
        return imageField || null;
    } catch {
        return null;
    }
}
