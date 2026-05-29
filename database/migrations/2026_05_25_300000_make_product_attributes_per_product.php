<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Clear store-scoped attribute data — it cannot be migrated to per-product
        // because there is no way to know which product each store attribute belongs to.
        Schema::disableForeignKeyConstraints();
        DB::table('product_variant_attribute_values')->truncate();
        DB::table('product_attribute_values')->truncate();
        DB::table('product_attributes')->truncate();
        Schema::enableForeignKeyConstraints();

        // Drop old store-scoped FK and unique constraint, remove store_id column
        Schema::table('product_attributes', function (Blueprint $table) {
            $table->dropForeign(['store_id']);
            $table->dropUnique(['store_id', 'slug']);
            $table->dropColumn('store_id');
        });

        // Add product_id column with its FK and unique constraint
        Schema::table('product_attributes', function (Blueprint $table) {
            $table->ulid('product_id')->after('id');
            $table->foreign('product_id')->references('id')->on('products')->cascadeOnDelete();
            $table->unique(['product_id', 'slug']);
        });
    }

    public function down(): void
    {
        Schema::table('product_attributes', function (Blueprint $table) {
            $table->dropForeign(['product_id']);
            $table->dropUnique(['product_id', 'slug']);
            $table->dropColumn('product_id');
        });

        Schema::table('product_attributes', function (Blueprint $table) {
            $table->ulid('store_id')->after('id');
            $table->foreign('store_id')->references('id')->on('stores')->cascadeOnDelete();
            $table->unique(['store_id', 'slug']);
        });
    }
};
