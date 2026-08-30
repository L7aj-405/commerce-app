<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A batch settlement from an external delivery company (Ozon, Sendit, or
 * any third-party carrier) for a set of COD orders it already delivered and
 * collected cash for. The carrier remits the NET amount (gross COD minus
 * its delivery fees and any adjustments) later, on its own schedule
 * (weekly/monthly) — this table is the foundation for recording that,
 * without building full carrier reconciliation yet.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('finance_cod_settlements', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->ulid('organization_id');
            $table->ulid('store_id')->nullable();

            // Free-text — not every settlement maps to the delivery_providers
            // catalog (a manually-tracked carrier is still valid here).
            $table->string('carrier_name')->nullable();

            $table->date('settlement_date');
            $table->date('period_start')->nullable();
            $table->date('period_end')->nullable();

            $table->decimal('gross_cod_amount', 12, 2)->default(0);
            $table->decimal('delivery_fees', 12, 2)->default(0);
            $table->decimal('adjustments', 12, 2)->default(0);
            $table->decimal('net_received', 12, 2)->default(0);

            $table->ulid('account_id')->nullable(); // usually Bank
            $table->string('reference')->nullable();
            $table->text('notes')->nullable();

            $table->string('status', 20)->default('draft'); // draft | settled | cancelled
            $table->timestamp('settled_at')->nullable();
            $table->ulid('created_by')->nullable();

            $table->timestamps();

            $table->foreign('organization_id')->references('id')->on('organizations')->cascadeOnDelete();
            $table->foreign('store_id')->references('id')->on('stores')->nullOnDelete();
            $table->foreign('account_id')->references('id')->on('finance_accounts')->nullOnDelete();
            $table->foreign('created_by')->references('id')->on('users')->nullOnDelete();

            $table->index(['organization_id', 'status']);
            $table->index(['organization_id', 'settlement_date']);
            $table->index(['organization_id', 'store_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('finance_cod_settlements');
    }
};
