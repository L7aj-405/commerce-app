<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * One row per (organization, delivery_provider) — `delivery_providers` is a
 * GLOBAL catalogue (App\Models\DeliveryProvider, no organization_id), so an
 * organization's own default fee / COD payout schedule / bank account lives
 * here instead, never on the catalogue row itself.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('delivery_provider_finance_settings', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->ulid('organization_id');
            $table->ulid('delivery_provider_id');

            $table->decimal('default_delivery_fee', 10, 2)->nullable();
            $table->decimal('default_return_fee', 10, 2)->default(0);
            $table->decimal('default_refusal_fee', 10, 2)->default(0);
            $table->decimal('cod_fee_fixed', 10, 2)->default(0);
            $table->decimal('cod_fee_percent', 5, 2)->default(0);

            $table->string('payout_frequency', 20)->default('weekly'); // weekly | biweekly | monthly
            // Anchor date the period grid is built from (e.g. the first Monday
            // this provider ever paid out) — advanced forward by
            // FinancePayoutFrequency::advance() to find the CURRENT period.
            $table->date('period_anchor_date')->nullable();
            $table->unsignedSmallInteger('payout_delay_days')->default(0);

            $table->ulid('default_bank_account_id')->nullable();
            $table->boolean('is_cod_enabled')->default(true);
            $table->boolean('is_active')->default(true);

            $table->timestamps();

            $table->foreign('organization_id', 'dp_finance_settings_org_fk')->references('id')->on('organizations')->cascadeOnDelete();
            $table->foreign('delivery_provider_id', 'dp_finance_settings_provider_fk')->references('id')->on('delivery_providers')->cascadeOnDelete();
            $table->foreign('default_bank_account_id', 'dp_finance_settings_account_fk')->references('id')->on('finance_accounts')->nullOnDelete();

            $table->unique(['organization_id', 'delivery_provider_id'], 'dp_finance_settings_org_provider_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('delivery_provider_finance_settings');
    }
};
