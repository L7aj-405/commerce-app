import { Plus, ImageOff, Layers } from 'lucide-react';

// Pull the first image out of an array, JSON string, or plain string.
function getFirstImage(imageField) {
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

function Thumb({ src, name, className }) {
    return src ? (
        <img src={src} alt={name} loading="lazy" className={`object-cover ${className}`} />
    ) : (
        <div className={`flex items-center justify-center text-content-muted/70 bg-surface-2 ${className}`}>
            <ImageOff className="w-1/3 h-1/3" />
        </div>
    );
}

export default function ProductCard({ product, currency = '$', onAdd, size = 'large', variant = 'card' }) {
    const outOfStock  = (product.stock ?? 0) <= 0;
    const image       = getFirstImage(product.images);
    const hasVariants = product.has_variants;
    // Variable products advertise their cheapest variant as "from X".
    const priceValue  = hasVariants ? (product.price_from ?? product.price) : product.price;
    const price       = `${hasVariants ? 'from ' : ''}${currency}${Number(priceValue).toFixed(2)}`;

    // ---- List row variant ---------------------------------------------------
    if (variant === 'list') {
        return (
            <button
                type="button"
                disabled={outOfStock}
                onClick={() => onAdd(product)}
                aria-label={hasVariants ? `Choose options for ${product.name}` : `Add ${product.name} to cart`}
                className={`group w-full flex items-center gap-3 rounded-lg border bg-surface-3 px-3 py-2 text-left transition ${
                    outOfStock
                        ? 'border-line opacity-50 cursor-not-allowed'
                        : 'border-line hover:border-indigo-500 hover:bg-surface-3/80'
                }`}
            >
                <Thumb src={image} name={product.name} className="w-11 h-11 rounded-md flex-shrink-0" />
                <div className="min-w-0 flex-1">
                    <div className="text-sm font-semibold text-content truncate flex items-center gap-1.5">
                        {product.name}
                        {hasVariants && (
                            <span className="inline-flex items-center gap-0.5 px-1.5 py-0.5 rounded-full bg-indigo-500/15 text-indigo-700 dark:text-indigo-300 text-[10px] font-medium">
                                <Layers className="w-2.5 h-2.5" />
                                {product.variant_count}
                            </span>
                        )}
                    </div>
                    <div className="text-xs text-content-muted font-mono truncate">{product.sku}</div>
                </div>
                <div className="text-right flex-shrink-0">
                    <div className="text-sm font-bold text-content">{price}</div>
                    <div className={`text-[11px] ${outOfStock ? 'text-red-600 dark:text-red-400' : 'text-content-muted'}`}>
                        stock {product.stock ?? 0}
                    </div>
                </div>
                <span className="flex-shrink-0 w-7 h-7 rounded-md bg-content/10 text-content-muted flex items-center justify-center group-hover:bg-indigo-600 group-hover:text-white transition">
                    {hasVariants ? <Layers className="w-4 h-4" /> : <Plus className="w-4 h-4" />}
                </span>
            </button>
        );
    }

    // ---- Card variant (small | large) --------------------------------------
    const small = size === 'small';

    return (
        <button
            type="button"
            disabled={outOfStock}
            onClick={() => onAdd(product)}
            aria-label={hasVariants ? `Choose options for ${product.name}` : `Add ${product.name} to cart`}
            className={`group text-left rounded-xl border bg-surface-3 shadow-sm dark:shadow-none transition ${
                outOfStock
                    ? 'border-line opacity-50 cursor-not-allowed'
                    : 'border-line hover:border-indigo-500 hover:shadow-lg hover:-translate-y-0.5'
            }`}
        >
            <div className="relative aspect-square overflow-hidden rounded-t-xl bg-surface-2">
                <Thumb src={image} name={product.name} className="w-full h-full transition group-hover:scale-105" />
                {outOfStock && (
                    <span className="absolute top-1.5 right-1.5 px-1.5 py-0.5 text-[9px] font-semibold uppercase tracking-wider rounded-full bg-red-600 text-white">
                        Out
                    </span>
                )}
                {! outOfStock && hasVariants && (
                    <span className="absolute top-1.5 left-1.5 inline-flex items-center gap-1 px-1.5 py-0.5 text-[9px] font-semibold rounded-full bg-black/55 text-white backdrop-blur-sm">
                        <Layers className="w-2.5 h-2.5" />
                        {product.variant_count} options
                    </span>
                )}
            </div>

            <div className={small ? 'p-2' : 'p-3'}>
                <div className={`font-semibold text-content truncate ${small ? 'text-xs' : 'text-sm'}`}>
                    {product.name}
                </div>
                {!small && <div className="text-xs text-content-muted font-mono truncate">{product.sku}</div>}
                <div className={`flex items-end justify-between ${small ? 'mt-1' : 'mt-2'}`}>
                    <span className={`font-bold text-content ${small ? 'text-sm' : 'text-base'}`}>{price}</span>
                    <span className={`text-[11px] ${outOfStock ? 'text-red-600 dark:text-red-400' : 'text-content-muted'}`}>
                        {product.stock ?? 0}
                    </span>
                </div>
            </div>
        </button>
    );
}
