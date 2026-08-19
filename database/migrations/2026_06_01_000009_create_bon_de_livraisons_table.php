<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('bon_de_livraisons', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->ulid('store_id');
            $table->ulid('pos_order_id')->nullable();
            $table->ulid('facture_id')->nullable();

            $table->string('bon_number')->unique();

            $table->enum('status', ['pending', 'preparing', 'ready', 'shipped', 'delivered', 'cancelled'])->default('pending');

            $table->date('issued_date');
            $table->date('expected_delivery_date')->nullable();
            $table->timestamp('shipped_at')->nullable();
            $table->timestamp('delivered_at')->nullable();

            $table->string('customer_name');
            $table->string('customer_phone')->nullable();
            $table->text('delivery_address');

            $table->unsignedInteger('total_items')->default(0);
            $table->unsignedInteger('items_delivered')->default(0);

            $table->string('tracking_number')->nullable();
            $table->string('shipping_method')->nullable();

            $table->string('pdf_path')->nullable();
            $table->text('notes')->nullable();

            $table->timestamps();

            $table->foreign('store_id')->references('id')->on('stores')->cascadeOnDelete();
            $table->foreign('pos_order_id')->references('id')->on('pos_orders')->nullOnDelete();
            $table->foreign('facture_id')->references('id')->on('factures')->nullOnDelete();

            $table->index(['store_id', 'issued_date']);
            $table->index('status');
            $table->index('bon_number');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('bon_de_livraisons');
    }
};
