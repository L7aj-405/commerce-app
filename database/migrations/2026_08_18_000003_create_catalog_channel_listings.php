<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

return new class extends Migration
{
    public function up(): void
    {
        // The canonical SKU belongs to a Store catalog, not to the whole SaaS.
        // Remote platforms are also allowed to omit SKU; manual ERP creation can
        // still require one at the validation layer.
        Schema::table('products', function (Blueprint $table): void {
            $table->dropUnique('products_sku_unique');
            $table->string('sku')->nullable()->change();
            $table->unique(['store_id', 'sku'], 'products_store_id_sku_unique');
        });

        Schema::table('product_variants', function (Blueprint $table): void {
            $table->string('sku')->nullable()->change();
        });

        Schema::create('product_channel_listings', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->ulid('product_id');
            $table->ulid('platform_connection_id');
            $table->string('external_product_id');
            $table->string('external_handle')->nullable();
            $table->string('sync_mode', 20)->default('pull');
            $table->string('sync_status', 20)->default('synced');
            $table->timestamp('last_pulled_at')->nullable();
            $table->timestamp('last_pushed_at')->nullable();
            $table->timestamp('remote_updated_at')->nullable();
            $table->json('sync_policy')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamps();

            $table->foreign('product_id', 'pcl_product_fk')->references('id')->on('products')->cascadeOnDelete();
            $table->foreign('platform_connection_id', 'pcl_connection_fk')->references('id')->on('platform_connections')->cascadeOnDelete();
            $table->unique(['product_id', 'platform_connection_id'], 'pcl_product_connection_unique');
            $table->unique(['platform_connection_id', 'external_product_id'], 'pcl_connection_external_unique');
            $table->index('external_product_id');
        });

        Schema::create('product_variant_channel_listings', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->ulid('product_id');
            $table->ulid('product_variant_id');
            $table->ulid('product_channel_listing_id');
            $table->ulid('platform_connection_id');
            $table->string('external_variant_id');
            $table->string('external_inventory_item_id')->nullable();
            $table->string('sync_status', 20)->default('synced');
            $table->timestamp('last_pulled_at')->nullable();
            $table->timestamp('last_pushed_at')->nullable();
            $table->timestamp('remote_updated_at')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamps();

            $table->foreign('product_id', 'pvcl_product_fk')->references('id')->on('products')->cascadeOnDelete();
            $table->foreign('product_variant_id', 'pvcl_variant_fk')->references('id')->on('product_variants')->cascadeOnDelete();
            $table->foreign('product_channel_listing_id', 'pvcl_listing_fk')->references('id')->on('product_channel_listings')->cascadeOnDelete();
            $table->foreign('platform_connection_id', 'pvcl_connection_fk')->references('id')->on('platform_connections')->cascadeOnDelete();
            $table->unique(['product_variant_id', 'platform_connection_id'], 'pvcl_variant_connection_unique');
            $table->unique(['platform_connection_id', 'external_variant_id'], 'pvcl_connection_external_unique');
            $table->index('external_variant_id');
        });

        $this->backfillLegacyMappings();
    }

    private function backfillLegacyMappings(): void
    {
        $now = now();

        DB::table('products')
            ->whereNotNull('external_id')
            ->where('external_id', '<>', '')
            ->whereNotNull('platform')
            ->orderBy('id')
            ->chunk(500, function ($products) use ($now): void {
                foreach ($products as $product) {
                    $connectionId = DB::table('platform_connections')
                        ->where('store_id', $product->store_id)
                        ->where('platform', $product->platform)
                        ->whereNull('deleted_at')
                        ->value('id');

                    if (! is_string($connectionId) || $connectionId === '') {
                        continue;
                    }

                    DB::table('product_channel_listings')->insertOrIgnore([
                        'id' => (string) Str::ulid(),
                        'product_id' => $product->id,
                        'platform_connection_id' => $connectionId,
                        'external_product_id' => (string) $product->external_id,
                        'sync_mode' => 'pull',
                        'sync_status' => 'synced',
                        'last_pulled_at' => $product->synced_at ?? null,
                        'metadata' => $product->metadata ?? null,
                        'created_at' => $now,
                        'updated_at' => $now,
                    ]);
                }
            });

        DB::table('product_variants')
            ->whereNotNull('external_id')
            ->where('external_id', '<>', '')
            ->orderBy('id')
            ->chunk(500, function ($variants) use ($now): void {
                foreach ($variants as $variant) {
                    $product = DB::table('products')
                        ->where('id', $variant->product_id)
                        ->first(['id', 'store_id', 'platform']);

                    if ($product === null || empty($product->platform)) {
                        continue;
                    }

                    $connectionId = DB::table('platform_connections')
                        ->where('store_id', $product->store_id)
                        ->where('platform', $product->platform)
                        ->whereNull('deleted_at')
                        ->value('id');

                    if (! is_string($connectionId) || $connectionId === '') {
                        continue;
                    }

                    $productListingId = DB::table('product_channel_listings')
                        ->where('product_id', $product->id)
                        ->where('platform_connection_id', $connectionId)
                        ->value('id');

                    if (! is_string($productListingId) || $productListingId === '') {
                        continue;
                    }

                    DB::table('product_variant_channel_listings')->insertOrIgnore([
                        'id' => (string) Str::ulid(),
                        'product_id' => $product->id,
                        'product_variant_id' => $variant->id,
                        'product_channel_listing_id' => $productListingId,
                        'platform_connection_id' => $connectionId,
                        'external_variant_id' => (string) $variant->external_id,
                        'sync_status' => 'synced',
                        'created_at' => $now,
                        'updated_at' => $now,
                    ]);
                }
            });
    }

    public function down(): void
    {
        Schema::dropIfExists('product_variant_channel_listings');
        Schema::dropIfExists('product_channel_listings');

        // A rollback after duplicate SKUs have been created in separate stores may
        // not be possible because the legacy schema required global uniqueness.
        Schema::table('products', function (Blueprint $table): void {
            $table->dropUnique('products_store_id_sku_unique');
            $table->unique('sku');
        });
    }
};
