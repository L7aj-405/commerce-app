<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Extends the existing ad-hoc external settlement (Phase 2) into a
 * provider-period-aware reconciliation, purely additively — every new
 * column is nullable and unused by the legacy manual flow
 * (App\Services\Finance\FinanceCodSettlementService::create()/settle()
 * behave byte-for-byte the same when `delivery_provider_id` is null).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('finance_cod_settlements', function (Blueprint $table) {
            // Null for a legacy manually-tracked carrier (kept via the
            // existing free-text carrier_name) — set only when this
            // settlement was generated from a delivery provider's payout
            // period (App\Services\Finance\FinanceCodPayoutPeriodService).
            $table->ulid('delivery_provider_id')->nullable()->after('carrier_name');

            // Snapshot of "what we expected", taken at create() time and
            // never touched again — settle()'s later actual-vs-expected
            // variance always compares against THIS, not the live
            // (possibly by-then-overwritten) net_received.
            $table->decimal('expected_net_amount', 12, 2)->nullable()->after('net_received');

            // What the accountant actually verified against the bank
            // statement. When present, settle() books THIS as the cash-in
            // amount instead of net_received (which still holds the old
            // create()-time estimate) and computes variance below.
            $table->decimal('actual_received_amount', 12, 2)->nullable()->after('expected_net_amount');
            $table->decimal('variance_amount', 12, 2)->nullable()->after('actual_received_amount');
            $table->date('received_at')->nullable()->after('variance_amount');
            $table->text('dispute_note')->nullable()->after('received_at');

            $table->foreign('delivery_provider_id')->references('id')->on('delivery_providers')->nullOnDelete();
            $table->index(['organization_id', 'delivery_provider_id', 'period_start'], 'fcs_org_provider_period_idx');
        });
    }

    public function down(): void
    {
        Schema::table('finance_cod_settlements', function (Blueprint $table) {
            $table->dropForeign(['delivery_provider_id']);
            $table->dropColumn([
                'delivery_provider_id', 'expected_net_amount', 'actual_received_amount',
                'variance_amount', 'received_at', 'dispute_note',
            ]);
        });
    }
};
