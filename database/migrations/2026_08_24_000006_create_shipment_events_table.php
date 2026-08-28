<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Append-only tracking history for one shipment. Never updated, only inserted. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('shipment_events', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->ulid('store_id');
            $table->ulid('shipment_id');

            $table->string('provider_code');
            $table->string('provider_status')->nullable();
            $table->string('normalized_status', 24);
            $table->string('message')->nullable();
            $table->json('raw_payload')->nullable();
            $table->timestamp('occurred_at')->nullable();

            $table->timestamp('created_at')->nullable();

            $table->foreign('store_id')->references('id')->on('stores')->cascadeOnDelete();
            $table->foreign('shipment_id')->references('id')->on('shipments')->cascadeOnDelete();

            $table->index(['shipment_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('shipment_events');
    }
};
