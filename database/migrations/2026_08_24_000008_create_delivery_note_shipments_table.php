<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('delivery_note_shipments', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->ulid('delivery_note_id');
            $table->ulid('shipment_id');
            $table->timestamps();

            $table->foreign('delivery_note_id')->references('id')->on('delivery_notes')->cascadeOnDelete();
            $table->foreign('shipment_id')->references('id')->on('shipments')->cascadeOnDelete();

            $table->unique(['delivery_note_id', 'shipment_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('delivery_note_shipments');
    }
};
