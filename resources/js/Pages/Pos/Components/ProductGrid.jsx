import { PackageSearch } from 'lucide-react';
import ProductCard from './ProductCard';

export default function ProductGrid({ products, currency, onAdd, view = 'large', columns = 3 }) {
    if (products.length === 0) {
        return (
            <div className="flex flex-col items-center justify-center py-16 text-content-muted">
                <PackageSearch className="w-12 h-12 mb-3 text-content-muted/60" strokeWidth={1.5} />
                <p className="text-sm font-medium text-content-muted">No products found</p>
                <p className="text-xs text-content-muted mt-1">Try a different search term or category.</p>
            </div>
        );
    }

    // List view — single stacked column of compact rows.
    if (view === 'list') {
        return (
            <div className="flex flex-col gap-2">
                {products.map((product) => (
                    <ProductCard
                        key={product.id}
                        product={product}
                        currency={currency}
                        onAdd={onAdd}
                        variant="list"
                    />
                ))}
            </div>
        );
    }

    // Card views — 2 columns on small screens, user-chosen count from `md` up.
    // The column count is driven by a CSS var so the Tailwind class stays static
    // (purge-safe) while the value remains dynamic.
    return (
        <div
            className="grid grid-cols-2 gap-3 md:[grid-template-columns:repeat(var(--pos-cols),minmax(0,1fr))]"
            style={{ '--pos-cols': columns }}
        >
            {products.map((product) => (
                <ProductCard
                    key={product.id}
                    product={product}
                    currency={currency}
                    onAdd={onAdd}
                    size={view === 'small' ? 'small' : 'large'}
                />
            ))}
        </div>
    );
}
