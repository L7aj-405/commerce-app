<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('stock_movements', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->ulid('warehouse_id');
            $table->ulid('product_id');
            $table->ulid('variant_id')->nullable();
            $table->ulid('user_id')->nullable();

            $table->enum('type', ['in', 'out', 'adjustment', 'return', 'damage']);
            $table->integer('quantity');
            $table->string('reference')->nullable();
            $table->text('notes')->nullable();

            $table->timestamps();

            $table->foreign('warehouse_id')->references('id')->on('warehouses');
            $table->foreign('product_id')->references('id')->on('products');
            $table->foreign('variant_id')->references('id')->on('product_variants')->nullOnDelete();
            $table->foreign('user_id')->references('id')->on('users')->nullOnDelete();

            $table->index(['warehouse_id', 'created_at']);
            $table->index('product_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('stock_movements');
    }
};
