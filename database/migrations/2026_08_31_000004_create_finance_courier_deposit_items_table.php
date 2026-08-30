<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Which pending COD orders are included in one internal courier cash deposit. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('finance_courier_deposit_items', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->ulid('finance_courier_deposit_id');
            $table->ulid('order_id');
            $table->decimal('amount', 12, 2); // the order's COD amount, captured at inclusion time

            $table->timestamps();

            $table->foreign('finance_courier_deposit_id', 'finance_courier_deposit_items_deposit_fk')
                ->references('id')->on('finance_courier_deposits')->cascadeOnDelete();
            $table->foreign('order_id')->references('id')->on('orders')->cascadeOnDelete();

            $table->unique(['finance_courier_deposit_id', 'order_id'], 'finance_courier_deposit_items_unique');
            $table->index('order_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('finance_courier_deposit_items');
    }
};
