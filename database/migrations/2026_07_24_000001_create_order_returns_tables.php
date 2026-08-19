<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Reverse logistics. A return is item-level, not order-level: a three-line order
 * can come back with two lines resellable and one damaged, which an order status
 * can never express.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('order_returns', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->ulid('store_id');

            // Order (online) or PosOrder — the return flow is identical for both.
            $table->nullableUlidMorphs('returnable');

            $table->string('reference');                                    // RET-YYYYMMDD-0001
            $table->string('status', 32)->default('awaiting_inspection');   // awaiting_inspection|inspecting|closed
            $table->string('reason', 64);                                   // refused|damaged_in_transit|wrong_item|customer_remorse|other
            $table->text('notes')->nullable();

            $table->char('flagged_by', 26)->nullable();
            $table->char('inspected_by', 26)->nullable();

            $table->timestamp('flagged_at');
            $table->timestamp('inspected_at')->nullable();
            $table->timestamp('closed_at')->nullable();

            $table->timestamps();

            $table->foreign('store_id')->references('id')->on('stores')->cascadeOnDelete();
            $table->foreign('flagged_by')->references('id')->on('users')->nullOnDelete();
            $table->foreign('inspected_by')->references('id')->on('users')->nullOnDelete();

            $table->unique(['store_id', 'reference']);
            $table->index(['store_id', 'status']);
        });

        Schema::create('order_return_items', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->ulid('order_return_id');

            // Nullable: online order lines live in a JSON column and may not map
            // to a local product, in which case the line is inspected but the
            // stock movement is skipped.
            $table->ulid('product_id')->nullable();
            $table->ulid('variant_id')->nullable();

            $table->string('product_name');
            $table->string('product_sku')->nullable();

            $table->integer('quantity_ordered');
            $table->integer('quantity_returned');

            $table->string('condition', 24)->nullable();     // resellable|damaged|missing (null = not yet inspected)
            $table->ulid('destination_warehouse_id')->nullable();
            $table->ulid('stock_ledger_id')->nullable();

            $table->decimal('refund_amount', 12, 2)->default(0);
            $table->text('inspection_notes')->nullable();
            $table->timestamp('dispositioned_at')->nullable();

            $table->timestamps();

            $table->foreign('order_return_id')->references('id')->on('order_returns')->cascadeOnDelete();
            $table->foreign('product_id')->references('id')->on('products')->nullOnDelete();
            $table->foreign('destination_warehouse_id')->references('id')->on('warehouses')->nullOnDelete();

            $table->index(['order_return_id', 'condition']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('order_return_items');
        Schema::dropIfExists('order_returns');
    }
};
