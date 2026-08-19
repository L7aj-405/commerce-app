<?php

declare(strict_types=1);

namespace App\Services\Inventory;

use App\Models\Stock;
use App\Models\Warehouse;

class InventoryCompatibilityBridge
{
    public function fromLegacyStock(Stock $stock): void
    {
        $stock->loadMissing(['product.store','variant']);
        $product=$stock->product; $warehouse=$stock->warehouse;
        if ($product === null || $warehouse === null || $product->store?->organization_id === null) return;
        $item=app(CatalogInventoryService::class)->forCatalog($product,$stock->variant);
        if ($item === null) return;
        $engine = app(InventoryEngine::class);
        $current = $engine->balance($item, $warehouse);

        // The legacy `stocks.quantity` column is now a SELLABLE projection.
        // Preserve active order/transfer reservations when old code changes it.
        $desiredOnHand = (int) $stock->quantity + (int) $current->reserved + (int) $current->transfer_reserved;
        $engine->setOnHand($item, $warehouse, $desiredOnHand, 'legacy_projection_import', null, null, 'Mirrored from legacy stocks table', false);
    }
}
