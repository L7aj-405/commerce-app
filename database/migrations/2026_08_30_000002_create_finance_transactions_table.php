<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('finance_transactions', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->ulid('organization_id');
            $table->ulid('store_id')->nullable();
            $table->ulid('account_id')->nullable();

            $table->string('direction', 10); // in | out | neutral
            $table->string('type', 40); // sale_created | payment_collected | cod_receivable_created | cod_collected | expense_paid | expense_unpaid_recorded | refund_paid | return_adjustment | bank_fee | manual_adjustment

            $table->decimal('amount', 12, 2);
            $table->string('currency', 3)->default('MAD');
            $table->dateTime('occurred_at');

            // Idempotency + traceability: which record this transaction was
            // derived from. A (source_type, source_id, type) triple may only
            // ever produce ONE transaction — see the unique index below.
            $table->string('source_type')->nullable();
            $table->string('source_id')->nullable();

            $table->string('reference')->nullable();
            $table->text('description')->nullable();
            $table->ulid('created_by')->nullable();
            $table->json('metadata')->nullable();

            $table->timestamps();
            // Deliberately NO softDeletes()/delete route — the ledger is
            // append-only. Corrections are new adjustment transactions.

            $table->foreign('organization_id')->references('id')->on('organizations')->cascadeOnDelete();
            $table->foreign('store_id')->references('id')->on('stores')->nullOnDelete();
            $table->foreign('account_id')->references('id')->on('finance_accounts')->nullOnDelete();
            $table->foreign('created_by')->references('id')->on('users')->nullOnDelete();

            // NULLs are distinct in a MySQL unique index, so manual
            // adjustments (no source) are never constrained by this — only
            // system-generated transactions tied to a concrete source are.
            $table->unique(['source_type', 'source_id', 'type'], 'finance_tx_source_type_unique');

            $table->index(['organization_id', 'occurred_at']);
            $table->index(['organization_id', 'type']);
            $table->index(['organization_id', 'direction']);
            $table->index(['organization_id', 'account_id']);
            $table->index(['organization_id', 'store_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('finance_transactions');
    }
};
