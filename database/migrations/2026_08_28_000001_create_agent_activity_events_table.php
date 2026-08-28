<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Append-only agent/operational activity ledger — the foundation for
 * role-aware dashboard metrics and (later, NOT this phase) a points/bonus
 * system. Written additively by existing workflow services (confirm/pick/
 * pack/deliver/inspect/adjust/receive) after each action already succeeds;
 * never read by, or blocking, any existing workflow.
 *
 * No `updated_at` — a ledger row is never edited after the fact.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('agent_activity_events', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->ulid('organization_id')->nullable();
            $table->ulid('store_id')->nullable();
            $table->ulid('user_id');

            // Snapshot of the actor's role slug at write time (see
            // User::accessProfileForStore()) — a role change later must not
            // rewrite history.
            $table->string('role_context')->nullable();

            $table->string('event_type');

            // What the event is about — Order|PosOrder|OrderReturn|
            // InventoryTransfer|Product, depending on event_type. Nullable:
            // some future event types may not have a single clear subject.
            $table->nullableUlidMorphs('subject');

            // Denormalized convenience FK for order-related events
            // specifically (confirmation/fulfillment/delivery). Not a strict
            // foreign key: it may point to either `orders` or `pos_orders`.
            $table->string('order_id', 26)->nullable();
            $table->string('order_item_id', 26)->nullable();

            $table->string('source_module', 32);

            $table->json('metadata')->nullable();

            $table->timestamp('occurred_at');
            $table->timestamp('created_at')->nullable();

            $table->foreign('store_id')->references('id')->on('stores')->nullOnDelete();
            $table->foreign('user_id')->references('id')->on('users')->cascadeOnDelete();

            $table->index(['store_id', 'event_type', 'occurred_at']);
            $table->index(['user_id', 'occurred_at']);
            $table->index('order_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('agent_activity_events');
    }
};
