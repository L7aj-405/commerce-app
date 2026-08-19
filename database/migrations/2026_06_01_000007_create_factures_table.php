<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('factures', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->ulid('store_id');
            $table->ulid('pos_order_id')->nullable();

            // Polymorphic source: unifies instant POS sales (PosOrder) and
            // deferred online orders (Order) behind one invoice pipeline.
            $table->nullableUlidMorphs('invoiceable');

            $table->string('invoice_number');

            $table->enum('status', ['draft', 'issued', 'sent', 'paid', 'overdue', 'cancelled', 'void'])->default('draft');
            $table->enum('payment_status', ['unpaid', 'partial', 'paid'])->default('unpaid');

            // Lifecycle / immutability markers.
            $table->timestamp('issued_at')->nullable();
            $table->char('issued_by', 26)->nullable();
            $table->timestamp('locked_at')->nullable();
            $table->timestamp('voided_at')->nullable();
            $table->string('void_reason')->nullable();

            $table->date('invoice_date');
            $table->date('due_date')->nullable();
            $table->timestamp('sent_at')->nullable();
            $table->timestamp('paid_at')->nullable();

            $table->string('customer_name');
            $table->string('customer_email')->nullable();
            $table->string('customer_phone')->nullable();
            $table->text('customer_address')->nullable();

            $table->decimal('subtotal', 12, 2)->default(0);
            $table->decimal('tax_amount', 12, 2)->default(0);
            $table->decimal('discount_amount', 12, 2)->default(0);
            $table->decimal('total_amount', 12, 2)->default(0);
            $table->decimal('amount_paid', 12, 2)->default(0);

            $table->enum('payment_method', ['cash', 'card', 'bank_transfer', 'check', 'mobile'])->default('cash');

            $table->string('pdf_path')->nullable();
            $table->text('notes')->nullable();

            $table->timestamps();

            $table->foreign('store_id')->references('id')->on('stores')->cascadeOnDelete();
            $table->foreign('pos_order_id')->references('id')->on('pos_orders')->nullOnDelete();
            $table->foreign('issued_by')->references('id')->on('users')->nullOnDelete();

            // Invoice numbers run a per-store (per-tenant) daily sequence.
            $table->unique(['store_id', 'invoice_number']);
            $table->index(['store_id', 'invoice_date']);
            $table->index(['status', 'payment_status']);
            $table->index('invoice_number');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('factures');
    }
};
