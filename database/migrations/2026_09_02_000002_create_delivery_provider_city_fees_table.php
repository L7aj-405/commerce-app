<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Manual, organization-entered per-city fee override for one delivery
 * provider — the fallback tier BELOW the provider's own synced pricing
 * (App\Models\DeliveryProviderCity.delivered_price/returned_price/
 * refused_price / price, store-scoped, auto-synced from Ozon/Sendit's own
 * /cities API) and ABOVE the provider's org-level default fee. `city_name`
 * is a free string (not a City/DeliveryProviderCity FK) — matches how
 * Shipment itself already stores the provider's own city name.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('delivery_provider_city_fees', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->ulid('organization_id');
            $table->ulid('delivery_provider_id');

            $table->string('city_name');
            $table->string('provider_city_code')->nullable();

            $table->decimal('delivery_fee', 10, 2);
            $table->decimal('return_fee', 10, 2)->default(0);
            $table->decimal('refusal_fee', 10, 2)->default(0);
            $table->decimal('cod_fee_fixed', 10, 2)->default(0);
            $table->decimal('cod_fee_percent', 5, 2)->default(0);

            $table->boolean('is_active')->default(true);
            $table->date('starts_at')->nullable();
            $table->date('ends_at')->nullable();

            $table->timestamps();

            $table->foreign('organization_id')->references('id')->on('organizations')->cascadeOnDelete();
            $table->foreign('delivery_provider_id')->references('id')->on('delivery_providers')->cascadeOnDelete();

            $table->index(['organization_id', 'delivery_provider_id', 'city_name'], 'dp_city_fees_org_provider_city_idx');
            $table->index(['organization_id', 'delivery_provider_id', 'is_active'], 'dp_city_fees_org_provider_active_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('delivery_provider_city_fees');
    }
};
