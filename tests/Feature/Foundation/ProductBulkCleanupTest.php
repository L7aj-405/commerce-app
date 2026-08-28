<?php

declare(strict_types=1);

use App\Models\Organization;
use App\Models\PosOrder;
use App\Models\PosOrderItem;
use App\Models\PosSession;
use App\Models\Product;
use App\Models\ProductAttribute;
use App\Models\ProductAttributeValue;
use App\Models\ProductChannelListing;
use App\Models\ProductVariant;
use App\Models\ProductVariantChannelListing;
use App\Models\PlatformConnection;
use App\Models\Store;
use App\Models\User;
use App\Models\Warehouse;
use App\Services\OrganizationProvisioner;

/** @return array{0: User, 1: Store, 2: Warehouse} */
function pclBMerchant(string $name = 'Bulk Cleanup Store'): array
{
    $user = User::factory()->create(['role' => 'store_admin', 'onboarding_completed_at' => now()]);
    $org = app(OrganizationProvisioner::class)->createOwnedOrganization($user, $name, Organization::TYPE_MERCHANT);
    $store = Store::create([
        'organization_id' => $org->id, 'user_id' => $user->id, 'name' => $name . ' Brand',
        'type' => 'online', 'status' => 'active', 'country' => 'MA', 'currency' => 'MAD',
    ]);
    $store->ensureDefaultRoles();
    $warehouse = Warehouse::withoutTenancy(fn () => Warehouse::create([
        'user_id' => $user->id, 'owner_organization_id' => $org->id, 'operator_organization_id' => $org->id,
        'name' => $name . ' Warehouse', 'type' => Warehouse::TYPE_STANDARD, 'country' => 'MA',
        'is_active' => true, 'is_default' => true,
    ]));
    $warehouse->accessibleOrganizations()->sync([$org->id => ['is_active' => true]]);
    $store->warehouses()->sync([$warehouse->id => ['is_primary' => true, 'priority' => 1]]);

    return [$user, $store, $warehouse];
}

function pclBWoo(Store $store, string $domain): PlatformConnection
{
    return PlatformConnection::withoutTenancy(fn () => PlatformConnection::create([
        'store_id' => $store->id, 'platform' => 'woocommerce', 'status' => 'active',
        'api_url' => "https://{$domain}", 'consumer_key' => 'ck_test', 'consumer_secret' => 'cs_test',
    ]));
}

it('previews purge safety, reporting allowed and blocked products separately', function (): void {
    [$owner, $store] = pclBMerchant();
    $safe = Product::withoutTenancy(fn () => Product::create([
        'store_id' => $store->id, 'name' => 'Safe Product', 'sku' => 'BULK-SAFE-1', 'type' => 'simple', 'status' => 'active', 'price' => 20,
    ]));
    $blocked = Product::withoutTenancy(fn () => Product::create([
        'store_id' => $store->id, 'name' => 'Blocked Product', 'sku' => 'BULK-BLOCKED-1', 'type' => 'simple', 'status' => 'active', 'price' => 20,
    ]));
    $session = PosSession::withoutTenancy(fn () => PosSession::create([
        'store_id' => $store->id, 'cashier_id' => $owner->id, 'status' => 'open', 'opened_at' => now(),
    ]));
    $posOrder = PosOrder::withoutTenancy(fn () => PosOrder::create([
        'store_id' => $store->id, 'pos_session_id' => $session->id, 'cashier_id' => $owner->id,
        'receipt_number' => 'PBULK-1', 'status' => 'completed', 'total_amount' => 20,
    ]));
    PosOrderItem::create([
        'pos_order_id' => $posOrder->id, 'product_id' => $blocked->id, 'product_name' => $blocked->name,
        'product_sku' => $blocked->sku, 'unit_price' => 20, 'subtotal' => 20, 'line_total' => 20, 'quantity' => 1,
    ]);

    $response = $this->actingAs($owner)->postJson('/dashboard/products/bulk/purge-preview', [
        'product_ids' => [$safe->id, $blocked->id],
    ])->assertOk();

    expect($response->json('summary.allowed'))->toBe(1)
        ->and($response->json('summary.blocked'))->toBe(1);

    $rows = collect($response->json('products'))->keyBy('product_id');
    expect($rows[$safe->id]['can_purge'])->toBeTrue()
        ->and($rows[$blocked->id]['can_purge'])->toBeFalse()
        ->and($rows[$blocked->id]['blockers'])->not->toBe([]);
});

it('rejects purge without the typed PURGE confirmation', function (): void {
    [$owner, $store] = pclBMerchant();
    $product = Product::withoutTenancy(fn () => Product::create([
        'store_id' => $store->id, 'name' => 'Confirm Product', 'sku' => 'BULK-CONFIRM-1', 'type' => 'simple', 'status' => 'active', 'price' => 20,
    ]));

    $this->actingAs($owner)->postJson('/dashboard/products/bulk/purge', [
        'product_ids' => [$product->id],
    ])->assertStatus(422);

    $this->actingAs($owner)->postJson('/dashboard/products/bulk/purge', [
        'product_ids' => [$product->id],
        'confirmation' => 'delete',
    ])->assertStatus(422);

    expect(Product::withoutTenancy(fn () => Product::query()->find($product->id)))->not->toBeNull();
});

it('purges a safe variable product and leaves no orphaned channel listings, options, or variants', function (): void {
    [$owner, $store] = pclBMerchant();
    $woo = pclBWoo($store, 'bulk-purge-woo.example.com');

    $product = Product::withoutTenancy(fn () => Product::create([
        'store_id' => $store->id, 'name' => 'Purge Variable Product', 'sku' => 'BULK-PURGE-1', 'type' => 'variable', 'status' => 'active', 'price' => 20,
    ]));
    $attribute = ProductAttribute::withoutTenancy(fn () => ProductAttribute::create([
        'product_id' => $product->id, 'name' => 'Size', 'slug' => 'size',
    ]));
    $value = ProductAttributeValue::withoutTenancy(fn () => ProductAttributeValue::create([
        'attribute_id' => $attribute->id, 'value' => 'M', 'slug' => 'm',
    ]));
    $variant = ProductVariant::withoutTenancy(fn () => ProductVariant::create([
        'product_id' => $product->id, 'name' => 'M', 'sku' => 'BULK-PURGE-1-M', 'price' => 20, 'cost' => 0,
    ]));
    $variant->syncAttributeValues([$value->id]);

    $listing = ProductChannelListing::withoutTenancy(fn () => ProductChannelListing::create([
        'product_id' => $product->id, 'platform_connection_id' => $woo->id, 'external_product_id' => 'woo-purge-1', 'sync_status' => 'synced',
    ]));
    $variantListing = ProductVariantChannelListing::withoutTenancy(fn () => ProductVariantChannelListing::create([
        'product_id' => $product->id, 'product_variant_id' => $variant->id, 'product_channel_listing_id' => $listing->id,
        'platform_connection_id' => $woo->id, 'external_variant_id' => 'woo-purge-1-m', 'sync_status' => 'synced',
    ]));

    $response = $this->actingAs($owner)->postJson('/dashboard/products/bulk/purge', [
        'product_ids' => [$product->id],
        'confirmation' => 'PURGE',
    ])->assertOk();

    expect($response->json('summary.purged'))->toBe(1)
        ->and($response->json('summary.skipped'))->toBe(0);

    expect(Product::withoutTenancy(fn () => Product::withTrashed()->find($product->id)))->toBeNull()
        ->and(ProductVariant::withoutTenancy(fn () => ProductVariant::withTrashed()->find($variant->id)))->toBeNull()
        ->and(ProductChannelListing::withoutTenancy(fn () => ProductChannelListing::query()->find($listing->id)))->toBeNull()
        ->and(ProductVariantChannelListing::withoutTenancy(fn () => ProductVariantChannelListing::query()->find($variantListing->id)))->toBeNull()
        ->and(ProductAttribute::withoutTenancy(fn () => ProductAttribute::query()->find($attribute->id)))->toBeNull()
        ->and(ProductAttributeValue::withoutTenancy(fn () => ProductAttributeValue::query()->find($value->id)))->toBeNull()
        ->and(\Illuminate\Support\Facades\DB::table('product_variant_attribute_values')->where('product_variant_id', $variant->id)->count())->toBe(0);
});

it('skips a blocked product in a mixed bulk purge and reports why, without touching the safe one', function (): void {
    [$owner, $store] = pclBMerchant();
    $safe = Product::withoutTenancy(fn () => Product::create([
        'store_id' => $store->id, 'name' => 'Mixed Safe', 'sku' => 'BULK-MIXED-SAFE', 'type' => 'simple', 'status' => 'active', 'price' => 20,
    ]));
    $blocked = Product::withoutTenancy(fn () => Product::create([
        'store_id' => $store->id, 'name' => 'Mixed Blocked', 'sku' => 'BULK-MIXED-BLOCKED', 'type' => 'simple', 'status' => 'active', 'price' => 20,
    ]));
    $session = PosSession::withoutTenancy(fn () => PosSession::create([
        'store_id' => $store->id, 'cashier_id' => $owner->id, 'status' => 'open', 'opened_at' => now(),
    ]));
    $posOrder = PosOrder::withoutTenancy(fn () => PosOrder::create([
        'store_id' => $store->id, 'pos_session_id' => $session->id, 'cashier_id' => $owner->id,
        'receipt_number' => 'PBULK-2', 'status' => 'completed', 'total_amount' => 20,
    ]));
    PosOrderItem::create([
        'pos_order_id' => $posOrder->id, 'product_id' => $blocked->id, 'product_name' => $blocked->name,
        'product_sku' => $blocked->sku, 'unit_price' => 20, 'subtotal' => 20, 'line_total' => 20, 'quantity' => 1,
    ]);

    $response = $this->actingAs($owner)->postJson('/dashboard/products/bulk/purge', [
        'product_ids' => [$safe->id, $blocked->id],
        'confirmation' => 'PURGE',
    ])->assertOk();

    expect($response->json('summary.purged'))->toBe(1)
        ->and($response->json('summary.skipped'))->toBe(1);

    expect(Product::withoutTenancy(fn () => Product::query()->find($safe->id)))->toBeNull()
        ->and(Product::withoutTenancy(fn () => Product::query()->find($blocked->id)))->not->toBeNull();
});

it('includes a recommended action for every skipped product in the purge result', function (): void {
    [$owner, $store] = pclBMerchant();
    $blocked = Product::withoutTenancy(fn () => Product::create([
        'store_id' => $store->id, 'name' => 'Recommend Product', 'sku' => 'BULK-RECOMMEND-1', 'type' => 'simple', 'status' => 'active', 'price' => 20,
    ]));
    $session = PosSession::withoutTenancy(fn () => PosSession::create([
        'store_id' => $store->id, 'cashier_id' => $owner->id, 'status' => 'open', 'opened_at' => now(),
    ]));
    $posOrder = PosOrder::withoutTenancy(fn () => PosOrder::create([
        'store_id' => $store->id, 'pos_session_id' => $session->id, 'cashier_id' => $owner->id,
        'receipt_number' => 'PBULK-REC-1', 'status' => 'completed', 'total_amount' => 20,
    ]));
    PosOrderItem::create([
        'pos_order_id' => $posOrder->id, 'product_id' => $blocked->id, 'product_name' => $blocked->name,
        'product_sku' => $blocked->sku, 'unit_price' => 20, 'subtotal' => 20, 'line_total' => 20, 'quantity' => 1,
    ]);

    $response = $this->actingAs($owner)->postJson('/dashboard/products/bulk/purge', [
        'product_ids' => [$blocked->id],
        'confirmation' => 'PURGE',
    ])->assertOk();

    $row = collect($response->json('results'))->firstWhere('product_id', $blocked->id);

    expect($row['purged'])->toBeFalse()
        ->and($row['recommended_action'])->not->toBeNull()
        ->and($row['recommended_action_label'])->not->toBeNull();
});

it('recommends archiving, not purging, a product with order history', function (): void {
    [$owner, $store] = pclBMerchant();
    $blocked = Product::withoutTenancy(fn () => Product::create([
        'store_id' => $store->id, 'name' => 'History Product', 'sku' => 'BULK-HISTORY-1', 'type' => 'simple', 'status' => 'active', 'price' => 20,
    ]));
    $session = PosSession::withoutTenancy(fn () => PosSession::create([
        'store_id' => $store->id, 'cashier_id' => $owner->id, 'status' => 'open', 'opened_at' => now(),
    ]));
    $posOrder = PosOrder::withoutTenancy(fn () => PosOrder::create([
        'store_id' => $store->id, 'pos_session_id' => $session->id, 'cashier_id' => $owner->id,
        'receipt_number' => 'PBULK-HIST-1', 'status' => 'completed', 'total_amount' => 20,
    ]));
    PosOrderItem::create([
        'pos_order_id' => $posOrder->id, 'product_id' => $blocked->id, 'product_name' => $blocked->name,
        'product_sku' => $blocked->sku, 'unit_price' => 20, 'subtotal' => 20, 'line_total' => 20, 'quantity' => 1,
    ]);

    $check = app(\App\Services\Catalog\ProductCleanupSafetyService::class)->check($blocked->fresh());

    expect($check['can_purge'])->toBeFalse()
        ->and($check['recommended_action'])->toBe(\App\Services\Catalog\ProductCleanupSafetyService::ACTION_ARCHIVE)
        ->and($check['recommended_action_label'])->toBe('Archive product');
});

it('recommends resetting the sync mapping for a product blocked by non-zero stock that also carries a channel mapping', function (): void {
    [, $store] = pclBMerchant();
    $woo = pclBWoo($store, 'recommend-mapping-woo.example.com');
    $product = Product::withoutTenancy(fn () => Product::create([
        'store_id' => $store->id, 'name' => 'Mapping And Stock Product', 'sku' => 'BULK-MAPPING-1', 'type' => 'simple', 'status' => 'active', 'price' => 20,
    ]));

    // A channel listing alone never blocks purge (purge can proceed with
    // only external listings and no history) — pair it with non-zero
    // LEGACY stock so the product is genuinely blocked, but by nothing more
    // than "has a mapping" + "non-zero stock". Written via withoutEvents()
    // to bypass InventoryCompatibilityBridge::fromLegacyStock() (a normal
    // Stock::create() always mirrors into the inventory engine and would
    // create a ledger entry too, which is a stronger "history" blocker and
    // would defeat the point of isolating the stock-only case here). Per the
    // documented priority (history > mapping > stock), the mapping wins.
    ProductChannelListing::withoutTenancy(fn () => ProductChannelListing::create([
        'product_id' => $product->id, 'platform_connection_id' => $woo->id, 'external_product_id' => 'woo-mapping-1', 'sync_status' => 'synced',
    ]));
    \App\Models\Stock::withoutTenancy(fn () => \App\Models\Stock::withoutEvents(fn () => \App\Models\Stock::create([
        'product_id' => $product->id, 'warehouse_id' => $store->getPrimaryWarehouse()->id, 'quantity' => 5, 'reorder_level' => 10,
    ])));

    $check = app(\App\Services\Catalog\ProductCleanupSafetyService::class)->check($product->fresh());

    expect($check['can_purge'])->toBeFalse()
        ->and($check['recommended_action'])->toBe(\App\Services\Catalog\ProductCleanupSafetyService::ACTION_RESET_SYNC)
        ->and($check['recommended_action_label'])->toBe('Reset sync mapping')
        ->and($check['recommended_connection_id'])->toBe($woo->id);
});

it('recommends reset sync mapping when purge is blocked by history but the product also carries a mapping, archive still wins', function (): void {
    [$owner, $store] = pclBMerchant();
    $woo = pclBWoo($store, 'recommend-mapping-woo-2.example.com');
    $product = Product::withoutTenancy(fn () => Product::create([
        'store_id' => $store->id, 'name' => 'Mapping And Return Product', 'sku' => 'BULK-MAPPING-2', 'type' => 'simple', 'status' => 'active', 'price' => 20,
    ]));
    ProductChannelListing::withoutTenancy(fn () => ProductChannelListing::create([
        'product_id' => $product->id, 'platform_connection_id' => $woo->id, 'external_product_id' => 'woo-mapping-2', 'sync_status' => 'synced',
    ]));
    $return = \App\Models\OrderReturn::withoutTenancy(fn () => \App\Models\OrderReturn::create([
        'store_id' => $store->id, 'returnable_type' => PosOrder::class, 'returnable_id' => (string) \Illuminate\Support\Str::ulid(),
        'reference' => 'RET-BULK-MAP-1', 'status' => 'awaiting_inspection', 'reason' => 'wrong_item', 'flagged_at' => now(),
    ]));
    \App\Models\OrderReturnItem::create([
        'order_return_id' => $return->id, 'product_id' => $product->id, 'product_name' => $product->name,
        'product_sku' => $product->sku, 'quantity_ordered' => 1, 'quantity_returned' => 1,
    ]);

    $check = app(\App\Services\Catalog\ProductCleanupSafetyService::class)->check($product->fresh());

    expect($check['can_purge'])->toBeFalse()
        ->and($check['recommended_action'])->toBe(\App\Services\Catalog\ProductCleanupSafetyService::ACTION_ARCHIVE);
});

it('archives skipped products via the follow-up bulk action', function (): void {
    [$owner, $store] = pclBMerchant();
    $blocked = Product::withoutTenancy(fn () => Product::create([
        'store_id' => $store->id, 'name' => 'Follow Up Archive Product', 'sku' => 'BULK-FOLLOWUP-ARCHIVE-1', 'type' => 'simple', 'status' => 'active', 'price' => 20,
    ]));
    $session = PosSession::withoutTenancy(fn () => PosSession::create([
        'store_id' => $store->id, 'cashier_id' => $owner->id, 'status' => 'open', 'opened_at' => now(),
    ]));
    $posOrder = PosOrder::withoutTenancy(fn () => PosOrder::create([
        'store_id' => $store->id, 'pos_session_id' => $session->id, 'cashier_id' => $owner->id,
        'receipt_number' => 'PBULK-FUA-1', 'status' => 'completed', 'total_amount' => 20,
    ]));
    PosOrderItem::create([
        'pos_order_id' => $posOrder->id, 'product_id' => $blocked->id, 'product_name' => $blocked->name,
        'product_sku' => $blocked->sku, 'unit_price' => 20, 'subtotal' => 20, 'line_total' => 20, 'quantity' => 1,
    ]);

    $purgeResponse = $this->actingAs($owner)->postJson('/dashboard/products/bulk/purge', [
        'product_ids' => [$blocked->id],
        'confirmation' => 'PURGE',
    ])->assertOk();

    $skippedIds = collect($purgeResponse->json('results'))->where('purged', false)->pluck('product_id')->all();
    expect($skippedIds)->toBe([$blocked->id]);

    $this->actingAs($owner)->postJson('/dashboard/products/bulk/archive', [
        'product_ids' => $skippedIds,
    ])->assertOk()->assertJsonPath('results.0.archived', true);

    expect($blocked->fresh()->status)->toBe('archived')
        // History is never touched by archive — the product row itself
        // stays alive, only its status flips.
        ->and(Product::withoutTenancy(fn () => Product::withTrashed()->find($blocked->id)))->not->toBeNull();
});

it('resets sync mappings for skipped products via the follow-up bulk action', function (): void {
    [$owner, $store] = pclBMerchant();
    $woo = pclBWoo($store, 'followup-reset-woo.example.com');
    $shopify = PlatformConnection::withoutTenancy(fn () => PlatformConnection::create([
        'store_id' => $store->id, 'platform' => 'shopify', 'connection_method' => 'admin_token',
        'status' => 'active', 'shop_domain' => 'followup-reset-shop.myshopify.com', 'access_token' => 'shpat_test',
    ]));
    $product = Product::withoutTenancy(fn () => Product::create([
        'store_id' => $store->id, 'name' => 'Follow Up Reset Product', 'sku' => 'BULK-FOLLOWUP-RESET-1', 'type' => 'simple', 'status' => 'active', 'price' => 20,
    ]));
    // Two mappings on the same product — the "reset all" follow-up must
    // clear both without needing the caller to pick a connection.
    $wooListing = ProductChannelListing::withoutTenancy(fn () => ProductChannelListing::create([
        'product_id' => $product->id, 'platform_connection_id' => $woo->id, 'external_product_id' => 'woo-followup-1', 'sync_status' => 'synced',
    ]));
    $shopifyListing = ProductChannelListing::withoutTenancy(fn () => ProductChannelListing::create([
        'product_id' => $product->id, 'platform_connection_id' => $shopify->id, 'external_product_id' => 'shop-followup-1', 'sync_status' => 'synced',
    ]));

    $response = $this->actingAs($owner)->postJson('/dashboard/products/bulk/reset-sync-all', [
        'product_ids' => [$product->id],
    ])->assertOk();

    expect($response->json('results.0.connections_reset'))->toBe(2)
        ->and(ProductChannelListing::withoutTenancy(fn () => ProductChannelListing::query()->find($wooListing->id)))->toBeNull()
        ->and(ProductChannelListing::withoutTenancy(fn () => ProductChannelListing::query()->find($shopifyListing->id)))->toBeNull()
        ->and(Product::withoutTenancy(fn () => Product::query()->find($product->id)))->not->toBeNull();
});

it('rejects a purge request for a product belonging to another store', function (): void {
    [, $storeA] = pclBMerchant('Bulk Purge Org A');
    [$ownerB, $storeB] = pclBMerchant('Bulk Purge Org B');
    $productA = Product::withoutTenancy(fn () => Product::create([
        'store_id' => $storeA->id, 'name' => 'Foreign Purge Product', 'sku' => 'BULK-FOREIGN-1', 'type' => 'simple', 'status' => 'active', 'price' => 20,
    ]));

    $response = $this->actingAs($ownerB)->postJson('/dashboard/products/bulk/purge', [
        'product_ids' => [$productA->id],
        'confirmation' => 'PURGE',
    ])->assertOk();

    expect($response->json('summary.matched'))->toBe(0)
        ->and($response->json('summary.purged'))->toBe(0);

    expect(Product::withoutTenancy(fn () => Product::query()->find($productA->id)))->not->toBeNull();
});
