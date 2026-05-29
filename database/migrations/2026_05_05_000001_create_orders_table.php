<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('orders', function (Blueprint $table): void {
            $table->char('id', 26)->primary();
            $table->char('store_id', 26);
            $table->foreign('store_id')->references('id')->on('stores')->cascadeOnDelete();
            $table->char('platform_connection_id', 26)->nullable();
            $table->foreign('platform_connection_id')->references('id')->on('platform_connections')->nullOnDelete();

            $table->string('platform_order_id');
            $table->string('order_number');
            $table->string('status')->default('pending');
            $table->decimal('total', 10, 2);
            $table->char('currency', 3);
            $table->string('customer_name');
            $table->string('customer_email')->nullable();
            $table->string('customer_phone')->nullable();
            $table->json('items');
            $table->json('platform_data');
            $table->timestamp('synced_at')->nullable();

            $table->text('notes')->nullable();

            $table->timestamps();
            $table->softDeletes();

            $table->unique(['platform_connection_id', 'platform_order_id']);
            $table->index(['store_id', 'status']);
            $table->index('customer_phone');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('orders');
    }
};
