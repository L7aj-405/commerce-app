<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('products', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->ulid('store_id');

            $table->string('external_id')->nullable();
            $table->string('platform')->nullable();

            $table->string('name');
            $table->text('description')->nullable();
            $table->string('sku');
            $table->string('barcode')->nullable();
            $table->enum('type', ['simple', 'variable'])->default('simple');
            $table->string('category')->nullable();
            $table->enum('status', ['active', 'draft', 'archived'])->default('draft');

            $table->decimal('price', 10, 2)->default(0);
            $table->decimal('cost', 10, 2)->nullable()->default(0);
            $table->decimal('compare_price', 10, 2)->nullable();

            $table->json('images')->nullable();
            $table->string('featured_image')->nullable();
            $table->string('seo_title')->nullable();
            $table->text('seo_description')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamp('synced_at')->nullable();

            $table->timestamps();
            $table->softDeletes();

            $table->foreign('store_id')->references('id')->on('stores')->cascadeOnDelete();
            $table->unique('sku');
            $table->index(['store_id', 'status']);
            $table->index('sku');
            $table->index('external_id');
            $table->index('category');
            $table->index('barcode');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('products');
    }
};
