<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Some transaction types are genuinely repeatable over an entity's
 * lifetime — an expense can be paid, marked back to unpaid (reversed), and
 * paid again with a corrected amount, any number of times. The original
 * (source_type, source_id, type) unique index assumed at most ONE
 * transaction per type per source EVER, which incorrectly blocked a second
 * `expense_paid` row once the first one had already been reversed.
 *
 * `sequence` disambiguates repeat cycles: it defaults to 0 and NEVER
 * changes for one-shot types (sale_created, cod_receivable_created,
 * payment_collected, cod_collected, refund_paid, etc.) — for those, the new
 * 4-column unique index behaves identically to the old 3-column one. Only
 * FinanceExpenseService's payment-cycle logic assigns a real (1, 2, 3, ...)
 * sequence, shared between one `expense_paid` and the `expense_payment_reversed`
 * that later reverses it, so each cycle's pair stays DB-uniquely
 * constrained while a brand new cycle is free to use the next number.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('finance_transactions', function (Blueprint $table) {
            $table->unsignedInteger('sequence')->default(0)->after('type');
        });

        Schema::table('finance_transactions', function (Blueprint $table) {
            $table->dropUnique('finance_tx_source_type_unique');
        });

        Schema::table('finance_transactions', function (Blueprint $table) {
            $table->unique(['source_type', 'source_id', 'type', 'sequence'], 'finance_tx_source_type_sequence_unique');
        });
    }

    public function down(): void
    {
        Schema::table('finance_transactions', function (Blueprint $table) {
            $table->dropUnique('finance_tx_source_type_sequence_unique');
        });

        Schema::table('finance_transactions', function (Blueprint $table) {
            $table->unique(['source_type', 'source_id', 'type'], 'finance_tx_source_type_unique');
        });

        Schema::table('finance_transactions', function (Blueprint $table) {
            $table->dropColumn('sequence');
        });
    }
};
