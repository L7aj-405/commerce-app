<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('product_variants', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->ulid('product_id');

            $table->string('external_id')->nullable();
            $table->string('name');
            $table->string('sku');

            $table->decimal('price', 10, 2)->default(0);
            $table->decimal('cost', 10, 2)->default(0);
            $table->decimal('compare_price', 10, 2)->nullable();

            $table->json('attributes')->nullable();
            $table->json('images')->nullable();
            $table->string('featured_image')->nullable();

            $table->timestamps();
            $table->softDeletes();

            $table->foreign('product_id')->references('id')->on('products')->cascadeOnDelete();

            // SKUs are unique per product, not globally.
            $table->unique(['product_id', 'sku'], 'product_variants_product_id_sku_unique');
            $table->index('product_id');
            $table->index('sku');
            $table->index('external_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('product_variants');
    }
};
