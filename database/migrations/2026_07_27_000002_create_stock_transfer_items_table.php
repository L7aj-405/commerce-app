<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('stock_transfer_items', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->ulid('stock_transfer_id');

            // Nullable + snapshot columns so a printed Bon de Sortie stays accurate
            // even if the product/variant is later renamed or removed.
            $table->ulid('product_id')->nullable();
            $table->ulid('variant_id')->nullable();
            $table->string('product_name');
            $table->string('variant_name')->nullable();
            $table->string('sku')->nullable();

            $table->unsignedInteger('quantity');
            $table->decimal('unit_price', 12, 2)->nullable(); // snapshot for slip valuation

            $table->timestamps();

            $table->foreign('stock_transfer_id')->references('id')->on('stock_transfers')->cascadeOnDelete();
            $table->foreign('product_id')->references('id')->on('products')->nullOnDelete();
            $table->foreign('variant_id')->references('id')->on('product_variants')->nullOnDelete();

            $table->index('stock_transfer_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('stock_transfer_items');
    }
};
