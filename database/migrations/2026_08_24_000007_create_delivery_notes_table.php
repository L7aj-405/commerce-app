<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** A carrier handover / "Bon de Livraison" batch, provider-side (Ozon's own BL, distinct from the internal MAN- manifest system). */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('delivery_notes', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->ulid('store_id');
            $table->ulid('delivery_connection_id');
            $table->string('provider_code');

            $table->string('provider_ref')->nullable();
            $table->string('status', 24)->default('draft'); // draft|saved

            $table->string('pdf_url')->nullable();
            $table->string('labels_pdf_url')->nullable();
            $table->json('raw_payload')->nullable();

            $table->timestamp('saved_at')->nullable();
            $table->timestamps();

            $table->foreign('store_id')->references('id')->on('stores')->cascadeOnDelete();
            $table->foreign('delivery_connection_id')->references('id')->on('delivery_connections')->cascadeOnDelete();
            $table->foreign('provider_code')->references('code')->on('delivery_providers')->cascadeOnUpdate();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('delivery_notes');
    }
};
