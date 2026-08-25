<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** A provider's own city list, as synced from its API (e.g. Ozon's /cities). */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('delivery_provider_cities', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->ulid('store_id');
            $table->string('provider_code');
            $table->string('provider_city_id');
            $table->string('city_name');
            $table->json('raw_payload')->nullable();
            $table->timestamps();

            $table->foreign('store_id')->references('id')->on('stores')->cascadeOnDelete();
            $table->foreign('provider_code')->references('code')->on('delivery_providers')->cascadeOnUpdate();

            $table->unique(['store_id', 'provider_code', 'provider_city_id'], 'dpc_store_provider_city_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('delivery_provider_cities');
    }
};
