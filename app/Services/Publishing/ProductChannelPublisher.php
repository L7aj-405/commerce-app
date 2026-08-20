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
 * canonical Shopify/WooCommerce mappers. Only Shopify and WooCommerce are
 * supported — this is the mapper-driven path CV2/CV3/CV5 add; it does not
 * replace ProductPushService, which the existing synchronous /publish
 * endpoint keeps using unchanged.
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

        if ($listing === null && ! $createMissingListings) {
            return $this->outcome('skipped', 'not_linked_to_channel', null, null);
        }

        $connector = new ShopifyConnector($connection);

        try {
            if ($listing !== null) {
                $payload = $map['payload'];
                unset($payload['variants']); // avoid duplicating variants on update — pushed per-variant below
                $result = $connector->updateProductPayload($listing->external_product_id, $payload);
            } else {
                $result = $connector->sendProductPayload($map['payload']);
            }
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

        if ($product->isVariable()) {
            $snapshot = ProductOptionSnapshot::build($product);
            $optionNames = array_column($snapshot['options'], 'name');
            $shopifyVariantsBySku = collect($result['variants'] ?? [])->keyBy(fn ($v) => (string) ($v['sku'] ?? ''));

            foreach ($snapshot['variants'] as $entry) {
                /** @var ProductVariant $variant */
                $variant = $entry['variant'];

                try {
                    $payload = $this->shopifyMapper->variantPayload($variant, $entry['combo'], $optionNames);
                    $variantListing = $variant->listingForConnection($connection);

                    if ($variantListing !== null) {
                        $r = $connector->updateVariantPayload($variantListing->external_variant_id, $payload);
                    } elseif (! empty($variant->sku) && $shopifyVariantsBySku->has($variant->sku)) {
                        // Just created together with the parent — match by SKU.
                        $r = ['success' => true, 'external_id' => (string) $shopifyVariantsBySku[$variant->sku]['id']];
                    } elseif ($createMissingListings) {
                        $r = $connector->createVariantPayload($externalProductId, $payload);
                    } else {
                        continue;
                    }

                    if ($r['success'] ?? false) {
                        $this->recordVariantListing($variant, $listing, $connection, (string) $r['external_id']);
                    } else {
                        $variantFailures[] = ['variant_id' => $variant->id, 'message' => $this->sanitize((string) ($r['message'] ?? 'push failed'))];
                    }
                } catch (Throwable $e) {
                    // One variant timing out/failing must never abort the rest.
                    $variantFailures[] = ['variant_id' => $variant->id, 'message' => $this->sanitize($e->getMessage())];
                }
            }
        }

        $message = $variantFailures === []
            ? 'Published to Shopify.'
            : 'Published to Shopify with ' . count($variantFailures) . ' variant failure(s).';

        return array_merge(
            $this->outcome('succeeded', $message, $externalProductId, null),
            ['variant_failures' => $variantFailures],
        );
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

    private function recordVariantListing(ProductVariant $variant, ProductChannelListing $listing, PlatformConnection $connection, string $externalId): ProductVariantChannelListing
    {
        return ProductVariantChannelListing::updateOrCreate(
            ['product_variant_id' => $variant->id, 'platform_connection_id' => $connection->id],
            [
                'product_id' => $variant->product_id,
                'product_channel_listing_id' => $listing->id,
                'external_variant_id' => $externalId,
                'sync_status' => 'synced',
                'last_pushed_at' => now(),
            ],
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
