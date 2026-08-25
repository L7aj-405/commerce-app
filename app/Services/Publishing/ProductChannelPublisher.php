<?php

declare(strict_types=1);

namespace App\Services\Publishing;

use App\Connectors\ShopifyConnector;
use App\Connectors\WooCommerceConnector;
use App\Exceptions\ConnectorException;
use App\Models\PlatformConnection;
use App\Models\Product;
use App\Models\ProductChannelListing;
use App\Models\ProductVariant;
use App\Models\ProductVariantChannelListing;
use App\Services\Publishing\Shopify\ShopifyProductPayloadMapper;
use App\Services\Publishing\WooCommerce\WooCommerceProductPayloadMapper;
use Throwable;

/**
 * Publishes one canonical Product to one PlatformConnection using the
 * canonical Shopify/WooCommerce mappers — options/variants always come from
 * ProductAttribute/ProductAttributeValue/ProductVariant (see
 * ProductOptionSnapshot), never from the legacy `attributes` JSON column.
 * Only Shopify and WooCommerce are supported. Used by both the queued
 * /publish-queued endpoint and, for Shopify, by ProductPublishService's
 * synchronous /publish endpoint — the two never diverge in behavior.
 * WooCommerce's synchronous path still goes through ProductPushService.
 */
class ProductChannelPublisher
{
    public function __construct(
        private readonly ShopifyProductPayloadMapper $shopifyMapper,
        private readonly WooCommerceProductPayloadMapper $wooMapper,
    ) {}

    /**
     * @return array{
     *     status: string,
     *     message: string,
     *     external_product_id: ?string,
     *     error_code: ?string,
     *     variant_failures: list<array{variant_id: string, message: string}>,
     * }
     */
    public function publish(Product $product, PlatformConnection $connection, bool $createMissingListings): array
    {
        if ($connection->status !== 'active') {
            return $this->outcome('failed', 'Connection is not active.', null, 'connection_inactive');
        }

        return match ($connection->platform) {
            'shopify' => $this->publishShopify($product, $connection, $createMissingListings),
            'woocommerce' => $this->publishWooCommerce($product, $connection, $createMissingListings),
            default => $this->outcome('failed', "Unsupported platform for canonical publish: {$connection->platform}.", null, 'unsupported_platform'),
        };
    }

    private function publishShopify(Product $product, PlatformConnection $connection, bool $createMissingListings): array
    {
        $map = $this->shopifyMapper->map($product);

        if (! $map['ready']) {
            return $this->outcome('failed', implode(' ', $map['errors']), null, 'not_ready');
        }

        $listing = $product->listingForConnection($connection);
        $isCreate = $listing === null;

        if ($isCreate && ! $createMissingListings) {
            return $this->outcome('skipped', 'not_linked_to_channel', null, null);
        }

        $connector = new ShopifyConnector($connection);
        // Driven by what the mapper actually decided (i.e. whether canonical
        // options exist), never by the `type` column directly — the two
        // could disagree mid-edit, and the payload/variant handling below
        // must always match what was actually mapped.
        $isVariable = array_key_exists('options', $map['payload']);
        $payload = $map['payload'];
        $snapshot = null;
        $optionNames = [];
        $staleRemoteVariants = false;

        if ($isVariable) {
            // Options and variants must always travel in the SAME Shopify
            // request — Shopify 422s ("Product options must have
            // corresponding variants") on an options-only update/create, and
            // updating options first then pushing variants in a later call
            // hits the same error on the first request. Rebuild the variant
            // array here (rather than trusting $map['payload']['variants']
            // verbatim) so each already-linked variant's remote id can be
            // attached, letting Shopify update it in place in this same
            // request instead of creating a duplicate.
            $snapshot = ProductOptionSnapshot::build($product);
            $optionNames = array_column($snapshot['options'], 'name');

            $payload['variants'] = array_map(function (array $entry) use ($connection, $optionNames) {
                $variant = $entry['variant'];
                $variantPayload = $this->shopifyMapper->variantPayload($variant, $entry['combo'], $optionNames);
                $variantListing = $variant->listingForConnection($connection);

                if ($variantListing !== null) {
                    $variantPayload['id'] = $variantListing->external_variant_id;
                }

                return $variantPayload;
            }, $snapshot['variants']);
        } elseif ($listing !== null) {
            // Simple product UPDATE — never send an id-less variants array
            // on the parent payload. Shopify SKU lives on the variant, not
            // the product; a variant object with no `id` risks Shopify
            // creating a SECOND default variant instead of updating the one
            // that already exists, which is exactly why the SKU never
            // appeared to change. The existing default variant is updated
            // explicitly, by id, further down instead.
            unset($payload['variants']);

            if (ProductVariantChannelListing::query()->where('product_channel_listing_id', $listing->id)->exists()) {
                // The product used to be variable and still has remote
                // variant mappings, but is simple locally now. Shopify
                // treats a product update's `variants` array as
                // authoritative — sending the lone default variant here
                // would silently truncate the other remote variants. Until
                // remote variant deletion is explicitly implemented, leave
                // Shopify's variants untouched and surface a warning
                // instead of risking data loss.
                $staleRemoteVariants = true;
            }
        }

        try {
            $result = $listing !== null
                ? $connector->updateProductPayload($listing->external_product_id, $payload)
                : $connector->sendProductPayload($payload);
        } catch (ConnectorException $e) {
            return $this->outcome('failed', $this->sanitize($e->getMessage()), null, 'connector_exception');
        } catch (Throwable $e) {
            return $this->outcome('failed', $this->sanitize($e->getMessage()), null, 'unexpected_exception');
        }

        if (! ($result['success'] ?? false)) {
            return $this->outcome('failed', $this->sanitize((string) ($result['message'] ?? 'Shopify push failed')), null, 'push_failed');
        }

        $externalProductId = (string) ($result['external_id'] ?? '');

        if ($externalProductId === '') {
            // A 2xx response with no usable id must never be recorded as a
            // listing — an empty external_product_id can never match a real
            // remote product on a later sync, so the next pull would create
            // a duplicate local product instead of matching this one.
            return $this->outcome('failed', 'Shopify did not return a product id — nothing was linked.', null, 'missing_external_id');
        }

        $listing = $this->recordProductListing($product, $connection, $externalProductId);

        $variantFailures = [];

        if ($isVariable) {
            $returnedVariants = collect($result['variants'] ?? []);

            foreach ($snapshot['variants'] as $entry) {
                /** @var ProductVariant $variant */
                $variant = $entry['variant'];

                // Match the local variant to what Shopify actually returned
                // by its option1/option2/option3 combination — never by SKU
                // (Shopify may not echo it back the same way) and never by
                // array position (Shopify can reorder).
                $expectedSlots = array_intersect_key(
                    $this->shopifyMapper->variantPayload($variant, $entry['combo'], $optionNames),
                    array_flip(['option1', 'option2', 'option3']),
                );

                $matched = $returnedVariants->first(function ($rv) use ($expectedSlots) {
                    foreach ($expectedSlots as $slot => $value) {
                        if ((string) ($rv[$slot] ?? '') !== (string) $value) {
                            return false;
                        }
                    }

                    return true;
                });

                if ($matched !== null && ! empty($matched['id'])) {
                    // Shopify includes inventory_item_id on every variant
                    // object in the product create/update response — free
                    // to capture here, no extra HTTP call needed. Stock
                    // sync (triggered separately, by stock adjustment —
                    // never by publish) reads this instead of fetching it.
                    $inventoryItemId = isset($matched['inventory_item_id']) ? (string) $matched['inventory_item_id'] : null;
                    $this->recordVariantListing($variant, $listing, $connection, (string) $matched['id'], $inventoryItemId);
                } else {
                    $variantFailures[] = [
                        'variant_id' => $variant->id,
                        'message' => 'Shopify did not return a matching variant for this option combination.',
                    ];
                }
            }
        } else {
            // Simple product — Shopify SKU lives on the default variant,
            // never the product parent, so the title/status update above is
            // never enough on its own. Resolve the default variant's remote
            // id (saved metadata first, then the response we already have,
            // then a fallback fetch), persist it for future publishes, and
            // make sure its sku/price/compare_at_price actually landed.
            $defaultVariantId = $listing->metadata['default_variant_id'] ?? null;
            $defaultVariantId ??= isset($result['variants'][0]['id']) ? (string) $result['variants'][0]['id'] : null;
            $defaultVariantId ??= $connector->getDefaultVariantId($externalProductId);

            // Same free-capture as the variable-product branch above —
            // inventory_item_id rides along on the variant object Shopify
            // already returned; stock sync never needs to fetch it later.
            $defaultInventoryItemId = $listing->metadata['default_inventory_item_id'] ?? null;
            $defaultInventoryItemId ??= isset($result['variants'][0]['inventory_item_id']) ? (string) $result['variants'][0]['inventory_item_id'] : null;

            if ($defaultVariantId === null) {
                $variantFailures[] = ['variant_id' => '', 'message' => 'Shopify did not return a default variant id.'];
            } else {
                $this->saveDefaultVariantMetadata($listing, $defaultVariantId, $defaultInventoryItemId);
                $defaultVariantPayload = $this->shopifyMapper->defaultVariantPayload($product);

                if ($isCreate) {
                    // Just created together with the parent — Shopify
                    // already applied this exact payload when creating the
                    // product's default variant. A second HTTP call would
                    // be redundant; just confirm the sku actually landed.
                    $returnedSku = (string) ($result['variants'][0]['sku'] ?? '');

                    if (($defaultVariantPayload['sku'] ?? '') !== $returnedSku) {
                        $variantFailures[] = ['variant_id' => '', 'message' => 'Shopify did not confirm the default variant SKU on create.'];
                    }
                } else {
                    try {
                        $variantResult = $connector->updateVariantPayload($defaultVariantId, $defaultVariantPayload);

                        if (! ($variantResult['success'] ?? false)) {
                            $variantFailures[] = [
                                'variant_id' => '',
                                'message' => $this->sanitize((string) ($variantResult['message'] ?? 'Shopify default variant SKU update failed')),
                            ];
                        }
                    } catch (Throwable $e) {
                        // A failed/timed-out variant call must never bubble
                        // up as an uncaught exception — the parent product
                        // update already succeeded and its listing is
                        // already recorded; this must surface as a clear
                        // partial-failure result instead.
                        $variantFailures[] = ['variant_id' => '', 'message' => $this->sanitize($e->getMessage())];
                    }
                }
            }
        }

        if (! $isVariable && $variantFailures !== []) {
            // The parent product update (title/status/etc.) already
            // succeeded and is recorded above — but a simple product publish
            // is never "fully successful" while its SKU may not have
            // changed on Shopify.
            $failureMessage = 'Product updated but Shopify default variant SKU update failed.';

            if ($staleRemoteVariants) {
                $failureMessage .= ' Some remote Shopify variants are no longer present locally and were not deleted.';
            }

            return array_merge(
                $this->outcome('failed', $failureMessage, $externalProductId, 'variant_sku_update_failed'),
                ['variant_failures' => $variantFailures],
            );
        }

        $message = $variantFailures === []
            ? 'Published to Shopify.'
            : 'Published to Shopify with ' . count($variantFailures) . ' variant mapping issue(s).';

        if ($staleRemoteVariants) {
            $message .= ' Some remote Shopify variants are no longer present locally and were not deleted.';
        }

        return array_merge(
            $this->outcome('succeeded', $message, $externalProductId, null),
            ['variant_failures' => $variantFailures],
        );
    }

    private function saveDefaultVariantMetadata(ProductChannelListing $listing, string $variantId, ?string $inventoryItemId): void
    {
        $metadata = $listing->metadata ?? [];
        $metadata['default_variant_id'] = $variantId;

        if ($inventoryItemId !== null) {
            $metadata['default_inventory_item_id'] = $inventoryItemId;
        }

        $listing->update(['metadata' => $metadata]);
    }

    private function publishWooCommerce(Product $product, PlatformConnection $connection, bool $createMissingListings): array
    {
        $map = $this->wooMapper->map($product);

        if (! $map['ready']) {
            return $this->outcome('failed', implode(' ', $map['errors']), null, 'not_ready');
        }

        $listing = $product->listingForConnection($connection);

        if ($listing === null && ! $createMissingListings) {
            return $this->outcome('skipped', 'not_linked_to_channel', null, null);
        }

        $connector = new WooCommerceConnector($connection);

        try {
            $result = $listing !== null
                ? $connector->updateProductPayload($listing->external_product_id, $map['payload'])
                : $connector->sendProductPayload($map['payload']);
        } catch (ConnectorException $e) {
            return $this->outcome('failed', $this->sanitize($e->getMessage()), null, 'connector_exception');
        } catch (Throwable $e) {
            return $this->outcome('failed', $this->sanitize($e->getMessage()), null, 'unexpected_exception');
        }

        if (! ($result['success'] ?? false)) {
            return $this->outcome('failed', $this->sanitize((string) ($result['message'] ?? 'WooCommerce push failed')), null, 'push_failed');
        }

        $externalProductId = (string) ($result['external_id'] ?? '');

        if ($externalProductId === '') {
            // Same rule as Shopify above — never record a listing without a
            // real remote id, or the next sync duplicates this product.
            return $this->outcome('failed', 'WooCommerce did not return a product id — nothing was linked.', null, 'missing_external_id');
        }

        $listing = $this->recordProductListing($product, $connection, $externalProductId);

        $variantFailures = [];

        foreach ($map['variations'] as $entry) {
            /** @var ProductVariant $variant */
            $variant = $entry['variant'];
            $payload = $entry['payload'];

            try {
                $variantListing = $variant->listingForConnection($connection);

                if ($variantListing !== null) {
                    $r = $connector->updateVariationPayload($externalProductId, $variantListing->external_variant_id, $payload);
                } elseif ($createMissingListings) {
                    $r = $connector->createVariationPayload($externalProductId, $payload);
                } else {
                    continue;
                }

                if ($r['success'] ?? false) {
                    $this->recordVariantListing($variant, $listing, $connection, (string) $r['external_id']);
                } else {
                    $variantFailures[] = ['variant_id' => $variant->id, 'message' => $this->sanitize((string) ($r['message'] ?? 'push failed'))];
                }
            } catch (Throwable $e) {
                // A single variation timeout must not fail the whole batch —
                // every other variation/connection keeps going.
                $variantFailures[] = ['variant_id' => $variant->id, 'message' => $this->sanitize($e->getMessage())];
            }
        }

        $message = $variantFailures === []
            ? 'Published to WooCommerce.'
            : 'Published to WooCommerce with ' . count($variantFailures) . ' variation failure(s).';

        return array_merge(
            $this->outcome('succeeded', $message, $externalProductId, null),
            ['variant_failures' => $variantFailures],
        );
    }

    private function recordProductListing(Product $product, PlatformConnection $connection, string $externalId): ProductChannelListing
    {
        return ProductChannelListing::updateOrCreate(
            ['product_id' => $product->id, 'platform_connection_id' => $connection->id],
            ['external_product_id' => $externalId, 'sync_mode' => 'bidirectional', 'sync_status' => 'synced', 'last_pushed_at' => now()],
        );
    }

    private function recordVariantListing(ProductVariant $variant, ProductChannelListing $listing, PlatformConnection $connection, string $externalId, ?string $inventoryItemId = null): ProductVariantChannelListing
    {
        $values = [
            'product_id' => $variant->product_id,
            'product_channel_listing_id' => $listing->id,
            'external_variant_id' => $externalId,
            'sync_status' => 'synced',
            'last_pushed_at' => now(),
        ];

        if ($inventoryItemId !== null) {
            $values['external_inventory_item_id'] = $inventoryItemId;
        }

        return ProductVariantChannelListing::updateOrCreate(
            ['product_variant_id' => $variant->id, 'platform_connection_id' => $connection->id],
            $values,
        );
    }

    /** @return array{status: string, message: string, external_product_id: ?string, error_code: ?string, variant_failures: list<array{variant_id: string, message: string}>} */
    private function outcome(string $status, string $message, ?string $externalProductId, ?string $errorCode): array
    {
        return [
            'status' => $status,
            'message' => $message,
            'external_product_id' => $externalProductId,
            'error_code' => $errorCode,
            'variant_failures' => [],
        ];
    }

    /** Strip anything that looks like a credential before it ever reaches a log line or stored message. */
    private function sanitize(string $message): string
    {
        return preg_replace('/(token|secret|key)"?\s*[:=]\s*"?[^\s",}]+/i', '$1=[redacted]', $message) ?? $message;
    }
}
