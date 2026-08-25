<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * One row per (user, order, type) — per-user "seen" state on purpose (the
 * ticket requires marking seen for the current user only, never globally).
 * The unique index below is the actual duplicate-prevention mechanism: a
 * retried/duplicated listener invocation for the same order+user+type is a
 * silent no-op via firstOrCreate(), never a second row.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('order_notifications', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('store_id')->constrained()->cascadeOnDelete();
            $table->foreignUlid('organization_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignUlid('user_id')->constrained()->cascadeOnDelete();
            $table->foreignUlid('order_id')->constrained()->cascadeOnDelete();
            $table->string('type', 32)->default('new_order');
            $table->string('source_platform', 32)->nullable();
            $table->string('title');
            $table->text('message')->nullable();
            $table->timestamp('seen_at')->nullable();
            $table->timestamps();

            $table->unique(['user_id', 'order_id', 'type']);
            $table->index(['store_id', 'user_id', 'seen_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('order_notifications');
    }
};
