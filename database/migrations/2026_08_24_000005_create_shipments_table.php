<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The rich, provider-specific record of one order shipped through an
 * external delivery provider (Ozon first). Deliberately separate from
 * order_shipments (the existing internal dispatch-board table) — that table
 * stays the generic "who is carrying this" bookkeeping used by the Dispatch
 * board/manifests; this table is the source of truth for provider API state
 * (raw payloads, normalized status, event history). order_shipment_id links
 * the two: sending to Ozon also creates a paired order_shipments row via the
 * existing DispatchService::assign(), so the order shows up on the existing
 * Dispatch board for free.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('shipments', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->ulid('store_id');
            $table->ulid('organization_id')->nullable(); // denormalized only, not scoped on

            $table->nullableUlidMorphs('shippable'); // Order | PosOrder

            $table->ulid('delivery_connection_id')->nullable();
            $table->string('provider_code');

            $table->string('provider_shipment_id')->nullable();
            $table->string('tracking_number')->nullable();
            $table->string('delivery_note_ref')->nullable();

            // Bridge to the existing internal dispatch-leg record.
            $table->char('order_shipment_id', 26)->nullable();

            $table->string('status', 24)->default('draft');
            $table->string('provider_status')->nullable(); // raw, never normalized in place

            $table->string('receiver_name');
            $table->string('phone');
            $table->ulid('city_id')->nullable(); // delivery_provider_cities.id
            $table->string('city_name')->nullable();
            $table->text('address');

            $table->decimal('cod_amount', 12, 2)->default(0);
            $table->decimal('delivery_fee', 12, 2)->nullable();

            $table->json('raw_payload')->nullable();

            $table->timestamp('sent_at')->nullable();
            $table->timestamp('delivered_at')->nullable();
            $table->timestamp('returned_at')->nullable();
            $table->timestamp('cancelled_at')->nullable();

            $table->timestamps();

            $table->foreign('store_id')->references('id')->on('stores')->cascadeOnDelete();
            $table->foreign('delivery_connection_id')->references('id')->on('delivery_connections')->nullOnDelete();
            $table->foreign('provider_code')->references('code')->on('delivery_providers')->cascadeOnUpdate();
            $table->foreign('order_shipment_id')->references('id')->on('order_shipments')->nullOnDelete();
            $table->foreign('city_id')->references('id')->on('delivery_provider_cities')->nullOnDelete();

            $table->unique(['store_id', 'shippable_type', 'shippable_id', 'provider_code'], 'shipments_one_active_per_order_provider');
            $table->index(['store_id', 'status']);
            $table->index('tracking_number');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('shipments');
    }
};
