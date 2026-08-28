<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Mirrors product_sync_batches/product_sync_results (same queued-job +
 * pollable-status pattern) so "Sync orders now" and "Full order resync" can
 * both return immediately with a batch_id instead of running the platform
 * API loop inside the HTTP request. imported/updated/skipped/failed_count +
 * last_error/started_at/completed_at are tracked on both the batch (summed
 * across connections) and each per-connection result row.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('order_sync_batches', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('store_id')->constrained()->cascadeOnDelete();
            $table->foreignUlid('organization_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignUlid('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('status')->default('queued'); // queued|running|completed|failed
            $table->unsignedInteger('total_count')->default(0);
            $table->unsignedInteger('imported_count')->default(0);
            $table->unsignedInteger('updated_count')->default(0);
            $table->unsignedInteger('skipped_count')->default(0);
            $table->unsignedInteger('failed_count')->default(0);
            $table->text('last_error')->nullable();
            $table->json('payload')->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();
        });

        Schema::create('order_sync_results', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('batch_id')->constrained('order_sync_batches')->cascadeOnDelete();
            $table->foreignUlid('store_id')->constrained()->cascadeOnDelete();
            $table->foreignUlid('platform_connection_id')->constrained()->cascadeOnDelete();
            $table->string('platform');
            $table->string('status')->default('queued'); // queued|running|completed|failed
            $table->boolean('full_resync')->default(false);
            $table->unsignedInteger('imported_count')->default(0);
            $table->unsignedInteger('updated_count')->default(0);
            $table->unsignedInteger('skipped_count')->default(0);
            $table->unsignedInteger('failed_count')->default(0);
            $table->text('last_error')->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();

            $table->index(['batch_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('order_sync_results');
        Schema::dropIfExists('order_sync_batches');
    }
};
