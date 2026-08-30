<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Which pending COD orders are included in one external carrier settlement. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('finance_cod_settlement_items', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->ulid('finance_cod_settlement_id');
            $table->ulid('order_id');
            $table->decimal('amount', 12, 2); // the order's COD amount, captured at inclusion time

            $table->timestamps();

            $table->foreign('finance_cod_settlement_id', 'finance_cod_settlement_items_settlement_fk')
                ->references('id')->on('finance_cod_settlements')->cascadeOnDelete();
            $table->foreign('order_id')->references('id')->on('orders')->cascadeOnDelete();

            $table->unique(['finance_cod_settlement_id', 'order_id'], 'finance_cod_settlement_items_unique');
            $table->index('order_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('finance_cod_settlement_items');
    }
};
