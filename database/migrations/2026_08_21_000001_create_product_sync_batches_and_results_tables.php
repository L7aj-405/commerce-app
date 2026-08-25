<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('product_sync_batches', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('store_id')->constrained()->cascadeOnDelete();
            $table->foreignUlid('organization_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignUlid('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('status')->default('pending'); // pending|running|completed|failed|partial
            $table->unsignedInteger('total_count')->default(0);
            $table->unsignedInteger('succeeded_count')->default(0);
            $table->unsignedInteger('failed_count')->default(0);
            $table->unsignedInteger('skipped_count')->default(0);
            $table->json('payload')->nullable();
            $table->timestamps();
        });

        Schema::create('product_sync_results', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('batch_id')->constrained('product_sync_batches')->cascadeOnDelete();
            $table->foreignUlid('store_id')->constrained()->cascadeOnDelete();
            $table->foreignUlid('platform_connection_id')->constrained()->cascadeOnDelete();
            $table->string('platform');
            $table->string('status')->default('queued'); // queued|running|succeeded|failed|skipped
            $table->unsignedInteger('created_count')->default(0);
            $table->unsignedInteger('updated_count')->default(0);
            $table->unsignedInteger('failed_item_count')->default(0);
            $table->text('message')->nullable();
            $table->timestamps();

            $table->index(['batch_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('product_sync_results');
        Schema::dropIfExists('product_sync_batches');
    }
};
