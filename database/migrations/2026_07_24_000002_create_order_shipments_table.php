<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The dispatch leg of an order: who is carrying it and how it is tracked.
 * Polymorphic — a packed POS delivery order and a packed online order dispatch
 * identically.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('order_shipments', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->ulid('store_id');
            $table->nullableUlidMorphs('shippable');           // Order | PosOrder

            $table->string('reference');                       // SHP-YYYYMMDD-0001

            // 'courier'  → third party, identified by carrier_name + tracking
            // 'internal' → one of our own people, identified by agent_id
            $table->string('carrier_type', 16);
            $table->string('carrier_name')->nullable();
            $table->string('tracking_number')->nullable();
            $table->string('tracking_url')->nullable();
            $table->char('agent_id', 26)->nullable();

            $table->string('manifest_reference')->nullable();

            $table->string('status', 24)->default('pending');  // pending|dispatched|delivered|failed
            $table->text('delivery_address')->nullable();

            // Cash on delivery: expected vs actually collected, for driver reconciliation.
            $table->decimal('cod_amount', 12, 2)->default(0);
            $table->decimal('cod_collected', 12, 2)->nullable();

            $table->text('notes')->nullable();
            $table->string('failure_reason')->nullable();

            $table->char('dispatched_by', 26)->nullable();
            $table->timestamp('dispatched_at')->nullable();
            $table->timestamp('delivered_at')->nullable();

            $table->timestamps();

            $table->foreign('store_id')->references('id')->on('stores')->cascadeOnDelete();
            $table->foreign('agent_id')->references('id')->on('users')->nullOnDelete();
            $table->foreign('dispatched_by')->references('id')->on('users')->nullOnDelete();

            $table->unique(['store_id', 'reference']);
            $table->index(['store_id', 'status']);
            $table->index('manifest_reference');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('order_shipments');
    }
};
