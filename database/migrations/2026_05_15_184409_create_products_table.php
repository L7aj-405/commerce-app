<?php

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
            $table->string('external_id')->nullable(); // WooCommerce ID
            $table->string('platform')->nullable(); // woocommerce, shopify
            
            $table->string('name');
            $table->text('description')->nullable();
            $table->string('sku')->unique();
            $table->enum('type', ['simple', 'variable'])->default('simple');
            $table->enum('status', ['active', 'draft', 'archived'])->default('draft');
            
            $table->decimal('price', 10, 2)->default(0);
            $table->decimal('cost', 10, 2)->nullable()->default(0);
            $table->decimal('compare_price', 10, 2)->nullable();
            
            $table->json('images')->nullable(); // ['url1', 'url2']
            $table->string('featured_image')->nullable();
            
            $table->string('seo_title')->nullable();
            $table->text('seo_description')->nullable();
            
            $table->json('metadata')->nullable(); // Custom fields
            $table->timestamp('synced_at')->nullable();
            
            $table->timestamps();
            $table->softDeletes();
            
            $table->foreign('store_id')->references('id')->on('stores')->onDelete('cascade');
            $table->index(['store_id', 'status']);
            $table->index(['sku']);
            $table->index(['external_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('products');
    }
};