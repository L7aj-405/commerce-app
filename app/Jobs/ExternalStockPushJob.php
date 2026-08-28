<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Models\InventoryAdjustment;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Services\Sync\ProductPushService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Phase S6 — pushes a product's (or one variant's) already-committed local
 * stock to external platform(s) in the background. This is the canonical
 * external stock push path used by the order/return/POS inventory events
 * (Phase O6) as well as `ProductController::adjustStock()`'s optional async
 * building block: it wraps the exact same ProductPushService methods
 * (Shopify via InventoryLevel, WooCommerce via stock_quantity) so every
 * caller shares one push implementation.
 *
 * A null `$platform` pushes to every active connection for the product's
 * store (what order/return/POS events want — they don't know in advance
 * which platforms are connected). A null `$variantId` pushes the simple
 * product's stock; a non-null one pushes that specific variant's.
 *
 * Local inventory is ALWAYS committed before this job even exists (the
 * caller adjusts via InventoryEngine first) — a failure here is only ever
 * logged and recorded on the optional InventoryAdjustment row, never rolled
 * back, matching the synchronous path's existing behavior of never undoing
 * a local adjustment because a platform push failed.
 */
class ExternalStockPushJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;
    public int $backoff = 30;
    public int $timeout = 60;

    public function __construct(
        public readonly string $productId,
        public readonly ?string $variantId,
        public readonly ?string $platform = null,
        public readonly ?string $adjustmentId = null,
    ) {}

    /** @return array<int, array{success: bool, message?: string}> succeeded/failed/skipped per connection, for the caller/tests — the queue worker itself only logs + records. */
    public function handle(ProductPushService $pushService): array
    {
        $product = Product::withoutTenancy(fn () => Product::query()->find($this->productId));

        if ($product === null) {
            $this->recordOutcome([]);

            return [];
        }

        $variant = $this->variantId !== null
            ? ProductVariant::withoutTenancy(fn () => ProductVariant::query()->find($this->variantId))
            : null;

        if ($this->variantId !== null && $variant === null) {
            $this->recordOutcome([]);

            return [];
        }

        $results = $variant !== null
            ? $pushService->pushVariantStock($variant, $this->platform)
            : $pushService->pushStock($product, $this->platform);

        if (empty($results)) {
            Log::info('ExternalStockPushJob: no listing for this platform, nothing pushed', [
                'product_id' => $product->id,
                'variant_id' => $variant?->id,
                'platform' => $this->platform,
            ]);

            $this->recordOutcome([]);

            return [];
        }

        foreach ($results as $result) {
            if (empty($result['success'])) {
                Log::warning('ExternalStockPushJob: platform stock push failed', [
                    'product_id' => $product->id,
                    'variant_id' => $variant?->id,
                    'platform' => $this->platform,
                    'message' => $result['message'] ?? null,
                ]);
            }
        }

        $this->recordOutcome($results);

        return $results;
    }

    public function failed(Throwable $e): void
    {
        if ($this->adjustmentId === null) {
            return;
        }

        InventoryAdjustment::withoutTenancy(function () use ($e): void {
            InventoryAdjustment::query()->whereKey($this->adjustmentId)->first()?->update([
                'sync_status' => 'failed',
                'sync_metadata' => ['error' => $e->getMessage()],
            ]);
        });
    }

    /**
     * Mirrors the outcome onto the InventoryAdjustment row (if one was passed)
     * so anything reading `sync_status`/`synced_at` — the audit trail, an
     * operations "stock sync status" display — still gets a meaningful
     * answer, even though this job (unlike the old SyncInventoryToWebhooks)
     * never mutates the adjustment itself for its own bookkeeping.
     *
     * @param  array<int, array{success: bool, message?: string}>  $results
     */
    private function recordOutcome(array $results): void
    {
        if ($this->adjustmentId === null) {
            return;
        }

        $anyFailed = collect($results)->contains(fn (array $r) => empty($r['success']));

        InventoryAdjustment::withoutTenancy(function () use ($results, $anyFailed): void {
            InventoryAdjustment::query()->whereKey($this->adjustmentId)->first()?->update([
                'sync_status' => $anyFailed ? 'failed' : 'synced',
                'synced_at' => now(),
                'sync_metadata' => ['results' => $results],
            ]);
        });
    }
}
