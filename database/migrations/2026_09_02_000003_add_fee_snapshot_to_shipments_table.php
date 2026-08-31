<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Fee snapshot — computed ONCE (App\Services\Finance\
 * FinanceDeliveryProviderFeeCalculator::snapshotForShipment(), guarded on
 * `fee_calculated_at IS NULL`) and never silently recalculated. If the
 * provider's tariff changes next month, an already-delivered order keeps the
 * fee that was true at the time — settlement math always reads THESE
 * columns, never live provider/city-fee settings.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('shipments', function (Blueprint $table) {
            $table->decimal('expected_delivery_fee', 10, 2)->nullable()->after('delivery_fee');
            $table->decimal('expected_return_fee', 10, 2)->nullable()->after('expected_delivery_fee');
            $table->decimal('expected_refusal_fee', 10, 2)->nullable()->after('expected_return_fee');
            $table->decimal('expected_cod_fee', 10, 2)->nullable()->after('expected_refusal_fee');
            $table->decimal('expected_total_carrier_fee', 10, 2)->nullable()->after('expected_cod_fee');
            // city_fee | provider_default | api_quote | manual_required
            $table->string('fee_source', 20)->nullable()->after('expected_total_carrier_fee');
            $table->timestamp('fee_calculated_at')->nullable()->after('fee_source');
            $table->json('fee_metadata')->nullable()->after('fee_calculated_at');

            // Manual override — an authorized user can correct a wrong/missing
            // snapshot with a reason, without unlocking or recalculating the
            // rest of the snapshot.
            $table->decimal('manual_fee_override', 10, 2)->nullable()->after('fee_metadata');
            $table->string('manual_fee_override_reason')->nullable()->after('manual_fee_override');
            $table->ulid('manual_fee_overridden_by')->nullable()->after('manual_fee_override_reason');
            $table->timestamp('manual_fee_overridden_at')->nullable()->after('manual_fee_overridden_by');

            $table->foreign('manual_fee_overridden_by')->references('id')->on('users')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('shipments', function (Blueprint $table) {
            $table->dropForeign(['manual_fee_overridden_by']);
            $table->dropColumn([
                'expected_delivery_fee', 'expected_return_fee', 'expected_refusal_fee',
                'expected_cod_fee', 'expected_total_carrier_fee', 'fee_source', 'fee_calculated_at', 'fee_metadata',
                'manual_fee_override', 'manual_fee_override_reason', 'manual_fee_overridden_by', 'manual_fee_overridden_at',
            ]);
        });
    }
};
