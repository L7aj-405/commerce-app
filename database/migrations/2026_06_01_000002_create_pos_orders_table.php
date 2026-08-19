<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('pos_orders', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->ulid('store_id');
            $table->ulid('pos_session_id');
            $table->char('cashier_id', 26);

            $table->string('receipt_number')->unique();

            $table->enum('status', ['completed', 'cancelled', 'pending_delivery'])->default('completed');

            // Unified fulfillment workflow (App\Enums\FulfillmentStatus).
            $table->string('fulfillment_status', 32)->default('completed');
            $table->timestamp('fulfillment_updated_at')->nullable();

            $table->decimal('subtotal', 12, 2)->default(0);
            $table->decimal('tax_amount', 12, 2)->default(0);
            $table->decimal('discount_amount', 12, 2)->default(0);
            $table->decimal('total_amount', 12, 2)->default(0);

            $table->enum('payment_method', ['cash', 'card', 'check', 'mobile', 'mixed'])->default('cash');
            $table->decimal('amount_paid', 12, 2)->default(0);
            $table->decimal('change_amount', 12, 2)->default(0);

            $table->string('customer_name')->nullable();
            $table->string('customer_phone')->nullable();
            $table->string('customer_email')->nullable();
            $table->string('customer_type')->default('individual');
            $table->string('company_name')->nullable();
            $table->string('tax_id')->nullable();

            $table->text('notes')->nullable();
            $table->text('delivery_address')->nullable();
            $table->decimal('delivery_fee', 12, 2)->default(0);

            $table->string('invoice_path')->nullable();
            $table->string('receipt_path')->nullable();

            // Who is working the order right now.
            $table->char('assigned_to', 26)->nullable();
            $table->timestamp('assigned_at')->nullable();

            $table->timestamps();

            $table->foreign('store_id')->references('id')->on('stores')->cascadeOnDelete();
            $table->foreign('pos_session_id')->references('id')->on('pos_sessions')->cascadeOnDelete();
            $table->foreign('cashier_id')->references('id')->on('users')->cascadeOnDelete();
            $table->foreign('assigned_to')->references('id')->on('users')->nullOnDelete();

            $table->index(['store_id', 'created_at']);
            $table->index('pos_session_id');
            $table->index('receipt_number');
            $table->index(['store_id', 'fulfillment_status'], 'pos_orders_store_fulfillment_index');
            $table->index(['store_id', 'assigned_to'], 'pos_orders_store_assignee_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('pos_orders');
    }
};
