<?php

declare(strict_types=1);

namespace App\Http\Controllers\Pos;

use App\Http\Controllers\Controller;
use App\Models\PosSession;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\Store;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class PosController extends Controller
{
    public function dashboard(Request $request): Response
    {
        $store   = $request->attributes->get('pos_store');
        $cashier = $request->user();

        $activeSession = PosSession::query()
            ->where('store_id', $store->id)
            ->where('cashier_id', $cashier->id)
            ->where('status', 'open')
            ->latest('opened_at')
            ->first();

        $products = Product::query()
            ->where('store_id', $store->id)
            ->where('status', 'active')
            ->with($this->variantEagerLoads())
            ->orderBy('name')
            ->get()
            ->map(fn (Product $p) => $this->presentProduct($p));

        return Inertia::render('Pos/Dashboard', [
            'store' => [
                'id'   => $store->id,
                'name' => $store->name,
            ],
            'products'      => $products,
            'categories'    => [],
            'activeSession' => $activeSession,
            'cashier'       => [
                'id'    => $cashier->id,
                'name'  => $cashier->name,
                'email' => $cashier->email,
            ],
        ]);
    }

    public function searchProducts(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'query'    => ['nullable', 'string', 'max:255'],
            'category' => ['nullable', 'string', 'max:255'],
        ]);

        $store = $request->attributes->get('pos_store');
        $query = $validated['query'] ?? '';

        $products = Product::query()
            ->where('store_id', $store->id)
            ->where('status', 'active')
            ->when($query !== '', function ($q) use ($query) {
                $q->where(function ($q) use ($query) {
                    $q->where('name', 'like', "%{$query}%")
                      ->orWhere('sku', 'like', "%{$query}%");
                });
            })
            ->with($this->variantEagerLoads())
            ->limit(50)
            ->get()
            ->map(fn (Product $p) => $this->presentProduct($p));

        return response()->json(['products' => $products]);
    }

    /**
     * Eager loads needed to present a product with its variants in one query set:
     * each variant's attribute values (grouped into selectable options) and its
     * sellable stock (summed as `stock_sum`, excluding damaged/quarantine).
     *
     * @return array<string, mixed>
     */
    private function variantEagerLoads(): array
    {
        return [
            'variants' => fn ($q) => $q
                ->with('attributeValues.attribute')
                ->withSum([
                    'stocks as stock_sum' => fn ($s) => $s->whereHas('warehouse', fn ($w) => $w->sellable()),
                ], 'quantity'),
        ];
    }

    private function presentProduct(Product $product): array
    {
        $hasVariants = $product->isVariable() && $product->variants->isNotEmpty();

        $base = [
            'id'           => $product->id,
            'name'         => $product->name,
            'sku'          => $product->sku,
            'price'        => (float) $product->price,
            'images'       => $product->images,
            'stock'        => $product->getDisplayStock(),
            'category'     => $product->category ?? null,
            'type'         => $product->type,
            'has_variants' => $hasVariants,
        ];

        if (! $hasVariants) {
            return $base + ['variant_count' => 0, 'price_from' => (float) $product->price];
        }

        $variants = $product->variants->map(fn (ProductVariant $v) => $this->presentVariant($v))->values();

        return $base + [
            'variant_count' => $variants->count(),
            // Cards show "from {price_from}" for variable products.
            'price_from'    => (float) $variants->min('price'),
            'attributes'    => $this->buildAttributeOptions($variants),
            'variants'      => $variants->all(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function presentVariant(ProductVariant $variant): array
    {
        return [
            'id'      => $variant->id,
            'name'    => $variant->getDisplayName(),   // "M / Red" or explicit variant name
            'sku'     => $variant->sku,
            'price'   => (float) $variant->price,
            'stock'   => (int) ($variant->stock_sum ?? 0),
            'image'   => $this->firstVariantImage($variant),
            // Attribute name => value, e.g. { "Size": "M", "Color": "Red" }.
            'options' => $this->variantOptions($variant),
        ];
    }

    /**
     * Attribute name => value for a single variant. Prefers the structured pivot
     * (attributeValues); falls back to the JSON `attributes` column for legacy /
     * unsynced variants so nothing is dropped from the selector.
     *
     * @return array<string, string>
     */
    private function variantOptions(ProductVariant $variant): array
    {
        if ($variant->relationLoaded('attributeValues') && $variant->attributeValues->isNotEmpty()) {
            return $variant->attributeValues
                ->groupBy(fn ($val) => $val->attribute?->name ?? '')
                ->map(fn ($vals) => $vals->pluck('value')->join(', '))
                ->all();
        }

        $attrs = $variant->getAttribute('attributes');

        if (empty($attrs) || ! is_array($attrs)) {
            return [];
        }

        return collect($attrs)
            ->map(fn ($v) => is_array($v) ? implode(', ', $v) : (string) $v)
            ->all();
    }

    /**
     * Build the ordered selector list the modal renders — one entry per attribute
     * with its distinct values across all variants (insertion order preserved).
     *
     * @param  \Illuminate\Support\Collection<int, array<string, mixed>>  $variants
     * @return array<int, array{name: string, values: array<int, string>}>
     */
    private function buildAttributeOptions($variants): array
    {
        $grouped = [];

        foreach ($variants as $variant) {
            foreach ($variant['options'] as $name => $value) {
                $grouped[$name] ??= [];
                if (! in_array($value, $grouped[$name], true)) {
                    $grouped[$name][] = $value;
                }
            }
        }

        return collect($grouped)
            ->map(fn (array $values, string $name) => ['name' => $name, 'values' => $values])
            ->values()
            ->all();
    }

    private function firstVariantImage(ProductVariant $variant): ?string
    {
        $images = $variant->getAttribute('images');

        if (is_array($images) && isset($images[0]) && is_string($images[0]) && $images[0] !== '') {
            return $images[0];
        }

        return $variant->featured_image ?: null;
    }
}
