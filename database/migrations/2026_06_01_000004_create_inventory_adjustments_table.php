<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('inventory_adjustments', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->ulid('store_id');
            $table->ulid('product_id');

            // Open-ended by nature (each feature touching stock adds a source);
            // a plain string, not an ENUM. See App\Enums / StockMovementWriter.
            $table->string('source', 32);

            $table->nullableUlidMorphs('adjustable');

            $table->integer('quantity_change');
            $table->integer('quantity_before');
            $table->integer('quantity_after');

            $table->enum('sync_status', ['pending', 'synced', 'failed'])->default('pending');
            $table->json('sync_metadata')->nullable();
            $table->timestamp('synced_at')->nullable();

            $table->text('notes')->nullable();

            $table->timestamps();

            $table->foreign('store_id')->references('id')->on('stores')->cascadeOnDelete();
            $table->foreign('product_id')->references('id')->on('products')->cascadeOnDelete();

            $table->index(['store_id', 'product_id']);
            $table->index('sync_status');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('inventory_adjustments');
    }
};
