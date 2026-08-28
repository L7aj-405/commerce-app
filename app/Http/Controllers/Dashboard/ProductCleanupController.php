<?php

declare(strict_types=1);

namespace App\Http\Controllers\Dashboard;

use App\Http\Controllers\Controller;
use App\Models\PlatformConnection;
use App\Models\Product;
use App\Models\Store;
use App\Services\Catalog\ProductCleanupSafetyService;
use App\Services\Catalog\ProductCleanupService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Safe bulk cleanup for imported products — archive, unlink a platform
 * mapping, reset sync state, or (only when safe) permanently purge. Every
 * action is scoped to the acting user's active store; ids for another
 * store's products are silently excluded, never acted on.
 */
class ProductCleanupController extends Controller
{
    /** Products the request asked for, re-scoped to the active store. Foreign ids are simply dropped. */
    private function scopedProducts(Store $store, array $productIds): \Illuminate\Support\Collection
    {
        return Product::query()
            ->where('store_id', $store->id)
            ->whereIn('id', $productIds)
            ->get();
    }

    private function requireStore(Request $request): Store
    {
        $store = $request->user()->getActiveStore();
        abort_if($store === null, 422, 'No active store.');

        return $store;
    }

    private function requireConnection(Store $store, string $connectionId): PlatformConnection
    {
        $connection = PlatformConnection::query()
            ->where('store_id', $store->id)
            ->find($connectionId);

        abort_if($connection === null, 422, 'Platform connection not found for this store.');

        return $connection;
    }

    public function archive(Request $request, ProductCleanupService $service): JsonResponse
    {
        $store = $this->requireStore($request);

        $validated = $request->validate([
            'product_ids' => ['required', 'array', 'min:1'],
            'product_ids.*' => ['string'],
        ]);

        $products = $this->scopedProducts($store, $validated['product_ids']);

        return response()->json([
            'results' => $service->archive($products),
            'summary' => ['requested' => count($validated['product_ids']), 'matched' => $products->count()],
        ]);
    }

    public function unlinkChannel(Request $request, ProductCleanupService $service): JsonResponse
    {
        $store = $this->requireStore($request);

        $validated = $request->validate([
            'product_ids' => ['required', 'array', 'min:1'],
            'product_ids.*' => ['string'],
            'platform_connection_id' => ['required', 'string'],
        ]);

        $connection = $this->requireConnection($store, $validated['platform_connection_id']);
        $products = $this->scopedProducts($store, $validated['product_ids']);

        return response()->json([
            'results' => $service->unlinkFromConnection($products, $connection),
            'summary' => ['requested' => count($validated['product_ids']), 'matched' => $products->count()],
            'warning' => 'Unlinking removes the external mapping. Future sync may create a new local product unless SKU matching is safe.',
        ]);
    }

    public function resetSync(Request $request, ProductCleanupService $service): JsonResponse
    {
        $store = $this->requireStore($request);

        $validated = $request->validate([
            'product_ids' => ['required', 'array', 'min:1'],
            'product_ids.*' => ['string'],
            'platform_connection_id' => ['required', 'string'],
        ]);

        $connection = $this->requireConnection($store, $validated['platform_connection_id']);
        $products = $this->scopedProducts($store, $validated['product_ids']);

        return response()->json([
            'results' => $service->resetSyncForConnection($products, $connection),
            'summary' => ['requested' => count($validated['product_ids']), 'matched' => $products->count()],
        ]);
    }

    /**
     * Resets sync mapping across every connection each given product happens
     * to be linked to — no platform_connection_id needed. Backs the "Reset
     * mappings for skipped products" bulk action on the purge results view,
     * where the skipped set may span several different platforms.
     */
    public function resetSyncAll(Request $request, ProductCleanupService $service): JsonResponse
    {
        $store = $this->requireStore($request);

        $validated = $request->validate([
            'product_ids' => ['required', 'array', 'min:1'],
            'product_ids.*' => ['string'],
        ]);

        $products = $this->scopedProducts($store, $validated['product_ids']);

        return response()->json([
            'results' => $service->resetAllSyncMappings($products),
            'summary' => ['requested' => count($validated['product_ids']), 'matched' => $products->count()],
        ]);
    }

    public function purgePreview(Request $request, ProductCleanupSafetyService $safety): JsonResponse
    {
        $store = $this->requireStore($request);

        $validated = $request->validate([
            'product_ids' => ['required', 'array', 'min:1'],
            'product_ids.*' => ['string'],
        ]);

        $products = $this->scopedProducts($store, $validated['product_ids']);
        $checks = $safety->checkMany($products);

        return response()->json([
            'products' => $checks->values(),
            'summary' => [
                'requested' => count($validated['product_ids']),
                'matched' => $products->count(),
                'allowed' => $checks->where('can_purge', true)->count(),
                'blocked' => $checks->where('can_purge', false)->count(),
            ],
        ]);
    }

    public function purge(Request $request, ProductCleanupService $service): JsonResponse
    {
        $store = $this->requireStore($request);

        $validated = $request->validate([
            'product_ids' => ['required', 'array', 'min:1'],
            'product_ids.*' => ['string'],
            'confirmation' => ['required', 'string', 'in:PURGE'],
        ]);

        $products = $this->scopedProducts($store, $validated['product_ids']);
        $results = $service->purge($products);

        return response()->json([
            'results' => $results,
            'summary' => [
                'requested' => count($validated['product_ids']),
                'matched' => $products->count(),
                'purged' => collect($results)->where('purged', true)->count(),
                'skipped' => collect($results)->where('purged', false)->count(),
            ],
        ]);
    }
}
