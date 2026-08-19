<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('stock_ledger', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->ulid('store_id');
            $table->ulid('product_id');

            $table->enum('type', ['purchase', 'sale', 'adjustment', 'return', 'damage', 'transfer']);

            $table->nullableUlidMorphs('source');

            $table->integer('quantity_change');
            $table->integer('stock_before');
            $table->integer('stock_after');

            $table->string('reference_number')->nullable();
            $table->text('notes')->nullable();

            $table->char('user_id', 26)->nullable();

            $table->timestamps();

            $table->foreign('store_id')->references('id')->on('stores')->cascadeOnDelete();
            $table->foreign('product_id')->references('id')->on('products')->cascadeOnDelete();
            $table->foreign('user_id')->references('id')->on('users')->nullOnDelete();

            $table->index(['store_id', 'product_id']);
            $table->index(['type', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('stock_ledger');
    }
};
