<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Models\PlatformConnection;
use App\Models\Product;
use App\Models\ProductPublishResult;
use App\Services\Publishing\ProductChannelPublisher;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Throwable;

/**
 * Publishes one canonical Product to one PlatformConnection and records the
 * outcome on its own ProductPublishResult row. One job per (product,
 * connection) pair so a slow/failing platform never blocks the others —
 * the HTTP endpoint that dispatches this returns immediately, before any of
 * these run.
 */
class ProductPublishJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 1;
    public int $timeout = 90;

    public function __construct(
        public readonly string $resultId,
        public readonly bool $createMissingListings = false,
    ) {}

    public function handle(ProductChannelPublisher $publisher): void
    {
        $result = ProductPublishResult::withoutTenancy(
            fn () => ProductPublishResult::query()->find($this->resultId),
        );

        if ($result === null) {
            return;
        }

        $result->update(['status' => ProductPublishResult::STATUS_RUNNING]);

        $product = Product::withoutTenancy(fn () => Product::query()->find($result->product_id));
        $connection = PlatformConnection::withoutTenancy(fn () => PlatformConnection::query()->find($result->platform_connection_id));

        if ($product === null || $connection === null) {
            $this->finish($result, [
                'status' => ProductPublishResult::STATUS_FAILED,
                'message' => 'Product or connection no longer exists.',
                'external_product_id' => null,
                'error_code' => 'not_found',
                'variant_failures' => [],
            ]);

            return;
        }

        try {
            $outcome = $publisher->publish($product, $connection, $this->createMissingListings);
        } catch (Throwable $e) {
            // The publisher already catches connector/HTTP failures per
            // connection/variant — reaching here means something unexpected
            // (e.g. a mapper bug). Still record a result instead of letting
            // the queue worker's failed_jobs table be the only trace.
            $outcome = [
                'status' => ProductPublishResult::STATUS_FAILED,
                'message' => 'Unexpected publish error: ' . $e->getMessage(),
                'external_product_id' => null,
                'error_code' => 'unexpected_exception',
                'variant_failures' => [],
            ];
        }

        $this->finish($result, $outcome);
    }

    /** @param array{status: string, message: string, external_product_id: ?string, error_code: ?string, variant_failures: list<array{variant_id: string, message: string}>} $outcome */
    private function finish(ProductPublishResult $result, array $outcome): void
    {
        $status = match ($outcome['status']) {
            'succeeded' => ProductPublishResult::STATUS_SUCCEEDED,
            'skipped' => ProductPublishResult::STATUS_SKIPPED,
            default => ProductPublishResult::STATUS_FAILED,
        };

        $result->update([
            'status' => $status,
            'message' => $outcome['message'],
            'external_product_id' => $outcome['external_product_id'],
            'error_code' => $outcome['error_code'],
        ]);

        foreach ($outcome['variant_failures'] as $failure) {
            ProductPublishResult::create([
                'batch_id' => $result->batch_id,
                'product_id' => $result->product_id,
                'product_variant_id' => $failure['variant_id'],
                'platform_connection_id' => $result->platform_connection_id,
                'platform' => $result->platform,
                'status' => ProductPublishResult::STATUS_FAILED,
                'message' => $failure['message'],
                'error_code' => 'variant_push_failed',
            ]);
        }

        $result->batch?->refreshCounts();
    }
}
