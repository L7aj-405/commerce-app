<?php

declare(strict_types=1);

use App\Models\Order;
use App\Models\PlatformConnection;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Phase OST2/OST3 — normalized order source metadata + indexes.
 *
 * Deliberately does NOT duplicate columns that already carry the same
 * information: `platform_order_id` is already the external order id,
 * `order_number` is already the platform's order number/reference
 * (ShopifyConnector/WooCommerceConnector/YouCanConnector's `number` field —
 * see BaseConnector::normalizeOrder()), and `platform_connection_id` is
 * already the authoritative online-order identity alongside it. Only the
 * genuinely missing fields are added here.
 *
 * `pos_orders` gets no equivalent migration: its source_type/source_platform/
 * source_channel_label are compile-time constants ("pos"/"pos"/"POS") and
 * source_store_name is already available via its `store` relation — adding
 * columns for values that never vary per row would be the exact duplication
 * this phase was told to avoid.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('orders', function (Blueprint $table): void {
            $table->ulid('organization_id')->nullable()->after('store_id');
            // Always 'online' today (this table has no other source) but a
            // real column, not a constant, so a future manually-created order
            // has somewhere to record source_type = 'manual'.
            $table->string('source_type', 16)->default('online')->after('platform_connection_id');
            $table->string('source_platform', 32)->nullable()->after('source_type');
            $table->string('source_store_name')->nullable()->after('source_platform');
            $table->string('source_store_domain')->nullable()->after('source_store_name');
            $table->string('source_channel_label')->nullable()->after('source_store_domain');
            $table->timestamp('imported_at')->nullable()->after('synced_at');

            $table->foreign('organization_id')->references('id')->on('organizations')->nullOnDelete();
        });

        Schema::table('orders', function (Blueprint $table): void {
            $table->index('organization_id');
            $table->index('store_id');
            $table->index('source_type');
            $table->index('source_platform');
            $table->index('platform_connection_id');
            $table->index('platform_order_id');
            // The composite (platform_connection_id, platform_order_id) pair is
            // already UNIQUE from the orders table's original creation —
            // stronger than the plain composite index OST3 asks for, so
            // nothing new is added for that pair specifically.
        });

        $this->backfill();
    }

    /**
     * One-time backfill for any pre-existing rows. Done in PHP (not a raw
     * cross-database JOIN UPDATE) so it works identically on MySQL in
     * production and SQLite in tests.
     */
    private function backfill(): void
    {
        Order::withoutTenancy(function (): void {
            Order::query()
                ->with('store:id,organization_id')
                ->whereNull('source_platform')
                ->chunkById(200, function ($orders): void {
                    $connectionIds = $orders->pluck('platform_connection_id')->filter()->unique()->values();
                    $connections = $connectionIds->isEmpty()
                        ? collect()
                        : PlatformConnection::withoutTenancy(fn () => PlatformConnection::query()
                            ->whereIn('id', $connectionIds)
                            ->get()
                            ->keyBy('id'));

                    foreach ($orders as $order) {
                        $connection = $order->platform_connection_id !== null
                            ? $connections->get($order->platform_connection_id)
                            : null;

                        $update = [
                            'organization_id' => $order->store?->organization_id,
                            'imported_at' => $order->synced_at ?? $order->created_at,
                        ];

                        if ($connection !== null) {
                            $update = array_merge($update, \App\Support\OrderSourceSummary::forConnection($connection));
                        } else {
                            $update['source_type'] = 'online';
                            $update['source_platform'] = null;
                        }

                        $order->newQuery()->whereKey($order->id)->update($update);
                    }
                });
        });
    }

    public function down(): void
    {
        Schema::table('orders', function (Blueprint $table): void {
            $table->dropForeign(['organization_id']);
            $table->dropColumn([
                'organization_id',
                'source_type',
                'source_platform',
                'source_store_name',
                'source_store_domain',
                'source_channel_label',
                'imported_at',
            ]);
        });
    }
};
