<?php

declare(strict_types=1);

namespace App\Services\Sync;

use App\Models\PlatformConnection;
use App\Models\Product;
use App\Models\Store;
use Illuminate\Support\Collection;

/**
 * Orchestrates explicit-target product publishing (SaaS -> platform).
 *
 * Every call requires the caller to name exactly which PlatformConnections
 * to publish to — this class never falls back to "every connected platform"
 * the way the old ProductController::push() did. Connection ids are always
 * re-verified against the product's own Store here, never trusted as-is
 * from the request.
 *
 * The actual platform API calls stay inside ProductPushService (which in
 * turn stays inside the WooCommerce/Shopify connectors) — this class only
 * decides WHICH (product, connection) pairs are eligible and routes them to
 * pushProduct() (update, existing listing) or createProduct() (create,
 * explicitly opted into via create_missing_listings).
 */
class ProductPublishService
{
    public function __construct(private readonly ProductPushService $pushService) {}

    /**
     * @param  array<int, string>  $connectionIds
     * @return array{product_id: string, results: array<int, array<string, mixed>>}
     */
    public function publish(Product $product, Store $store, array $connectionIds, bool $createMissingListings = false): array
    {
        return [
            'product_id' => $product->id,
            'results' => $this->publishOne($product, $store, $connectionIds, $createMissingListings),
        ];
    }

    /**
     * @param  array<int, string>  $productIds
     * @param  array<int, string>  $connectionIds
     * @return array{summary: array<string, int>, results: array<int, array<string, mixed>>}
     */
    public function bulkPublish(array $productIds, Store $store, array $connectionIds, bool $createMissingListings = false): array
    {
        $products = Product::query()
            ->where('store_id', $store->id)
            ->whereIn('id', $productIds)
            ->get();

        $results = [];
        $succeeded = 0;
        $failed = 0;
        $skipped = 0;

        foreach ($products as $product) {
            foreach ($this->publishOne($product, $store, $connectionIds, $createMissingListings) as $row) {
                $row['product_id'] = $product->id;
                $results[] = $row;

                match ($row['status']) {
                    'succeeded' => $succeeded++,
                    'failed' => $failed++,
                    'skipped' => $skipped++,
                    default => null,
                };
            }
        }

        return [
            'summary' => [
                'products' => $products->count(),
                'connections' => count($connectionIds),
                'succeeded' => $succeeded,
                'failed' => $failed,
                'skipped' => $skipped,
            ],
            'results' => $results,
        ];
    }

    /** @return array<int, array<string, mixed>> */
    private function publishOne(Product $product, Store $store, array $connectionIds, bool $createMissingListings): array
    {
        $connections = $this->resolveConnections($store, $connectionIds);
        $results = [];

        // Any requested id that isn't one of this store's own connections —
        // wrong store, wrong organization, or simply doesn't exist — is
        // reported, never silently dropped or (worse) trusted.
        $foundIds = $connections->pluck('id')->all();
        foreach ($connectionIds as $requestedId) {
            if (! in_array($requestedId, $foundIds, true)) {
                $results[] = [
                    'connection_id' => $requestedId,
                    'platform' => null,
                    'status' => 'failed',
                    'message' => "Connection does not belong to this product's store.",
                    'listing_id' => null,
                ];
            }
        }

        $linkedIds = [];
        $unlinkedConnections = [];

        foreach ($connections as $connection) {
            if ($connection->status !== 'active') {
                $results[] = [
                    'connection_id' => $connection->id,
                    'platform' => $connection->platform,
                    'status' => 'failed',
                    'message' => 'Connection is not active.',
                    'listing_id' => null,
                ];
                continue;
            }

            $isLinked = $product->listingForConnection($connection) !== null
                || $product->externalIdForConnection($connection) !== null;

            if ($isLinked) {
                $linkedIds[] = $connection->id;
            } else {
                $unlinkedConnections[] = $connection;
            }
        }

        if (! empty($linkedIds)) {
            foreach ($this->pushService->pushProduct($product, null, $linkedIds) as $r) {
                $results[] = $this->mapResult($r);
            }
        }

        foreach ($unlinkedConnections as $connection) {
            if (! $createMissingListings) {
                $results[] = [
                    'connection_id' => $connection->id,
                    'platform' => $connection->platform,
                    'status' => 'skipped',
                    'message' => 'not_linked_to_channel',
                    'listing_id' => null,
                ];
            }
        }

        if ($createMissingListings) {
            $unlinkedIds = array_map(fn (PlatformConnection $c) => $c->id, $unlinkedConnections);

            if (! empty($unlinkedIds)) {
                foreach ($this->pushService->createProduct($product, null, $unlinkedIds) as $r) {
                    $results[] = $this->mapResult($r);
                }
            }
        }

        return $results;
    }

    /** @param array<int, string> $connectionIds */
    private function resolveConnections(Store $store, array $connectionIds): Collection
    {
        return PlatformConnection::query()
            ->where('store_id', $store->id)
            ->whereIn('id', $connectionIds)
            ->get();
    }

    /** @param array<string, mixed> $result ProductPushService's raw per-connection result */
    private function mapResult(array $result): array
    {
        return [
            'connection_id' => $result['connection_id'] ?? null,
            'platform' => $result['platform'] ?? null,
            'status' => ($result['success'] ?? false) ? 'succeeded' : 'failed',
            'message' => $result['message'] ?? ($result['error'] ?? ''),
            'listing_id' => $result['listing_id'] ?? null,
        ];
    }
}
