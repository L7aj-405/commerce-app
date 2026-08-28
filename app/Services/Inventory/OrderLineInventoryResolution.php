<?php

declare(strict_types=1);

namespace App\Services\Inventory;

use App\Models\InventoryItem;

/**
 * The outcome of resolving one online order line to a local catalog
 * record and (when possible) an InventoryItem — see
 * OrderLineInventoryResolver. `mappingSource` is never guessed by a caller;
 * it is the authoritative reason this specific outcome was reached, and
 * drives both the "unmapped" confirmation guard (OrderLineItems/
 * OrderWorkflowService) and the Waiting Stock diagnostics UI.
 */
final class OrderLineInventoryResolution
{
    public const SOURCE_VARIANT_LISTING = 'variant_listing';
    public const SOURCE_PRODUCT_LISTING_SIMPLE = 'product_listing_simple';
    public const SOURCE_PRODUCT_LISTING_VARIANT = 'product_listing_variant';
    public const SOURCE_SKU_VARIANT_FALLBACK = 'sku_variant_fallback';
    public const SOURCE_SKU_PRODUCT_FALLBACK = 'sku_product_fallback';
    public const SOURCE_LEGACY_EXTERNAL_ID = 'legacy_external_id';
    public const SOURCE_LOCAL = 'local';
    public const SOURCE_AMBIGUOUS = 'ambiguous';
    public const SOURCE_UNMAPPED = 'unmapped';

    public function __construct(
        public readonly ?string $productId,
        public readonly ?string $productVariantId,
        public readonly ?InventoryItem $inventoryItem,
        public readonly string $mappingSource,
        public readonly ?string $mappingMessage = null,
    ) {}

    public function isMapped(): bool
    {
        return $this->inventoryItem !== null;
    }

    public static function unmapped(string $message): self
    {
        return new self(null, null, null, self::SOURCE_UNMAPPED, $message);
    }
}
