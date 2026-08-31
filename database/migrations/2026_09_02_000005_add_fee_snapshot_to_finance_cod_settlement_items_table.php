<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Per-order fee audit trail — copied from the order's Shipment fee snapshot
 * at the moment it was attached to a settlement, so the settlement keeps an
 * exact historical record even if the shipment row were ever touched later.
 * Nullable/unused for a legacy manual settlement (no provider fee snapshot
 * exists for those orders).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('finance_cod_settlement_items', function (Blueprint $table) {
            $table->decimal('expected_fee', 10, 2)->nullable()->after('amount');
            $table->string('fee_source', 20)->nullable()->after('expected_fee');
        });
    }

    public function down(): void
    {
        Schema::table('finance_cod_settlement_items', function (Blueprint $table) {
            $table->dropColumn(['expected_fee', 'fee_source']);
        });
    }
};
