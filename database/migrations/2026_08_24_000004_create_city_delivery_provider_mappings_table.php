<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Links the platform's own `cities` (shared, provider-agnostic reference
 * table) to one provider's city id. Kept off `cities` itself since a city
 * may map to several providers over time.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('city_delivery_provider_mappings', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->ulid('store_id');
            $table->ulid('city_id');
            $table->string('provider_code');
            $table->ulid('delivery_provider_city_id');
            $table->timestamps();

            $table->foreign('store_id')->references('id')->on('stores')->cascadeOnDelete();
            $table->foreign('city_id')->references('id')->on('cities')->cascadeOnDelete();
            $table->foreign('provider_code')->references('code')->on('delivery_providers')->cascadeOnUpdate();
            $table->foreign('delivery_provider_city_id', 'cdpm_provider_city_fk')
                ->references('id')->on('delivery_provider_cities')->cascadeOnDelete();

            $table->unique(['store_id', 'city_id', 'provider_code'], 'cdpm_store_city_provider_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('city_delivery_provider_mappings');
    }
};
