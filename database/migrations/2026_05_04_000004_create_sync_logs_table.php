<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('sync_logs', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->ulid('store_id')->nullable();
            $table->string('platform', 50)->nullable();
            $table->ulid('platform_connection_id');

            $table->enum('direction', ['pull', 'push'])->default('pull');
            $table->enum('action', [
                'product', 'variant', 'variant_create', 'variant_delete', 'stock', 'order', 'customer',
            ])->nullable();

            $table->string('entity_id')->nullable();
            $table->string('external_id')->nullable();
            $table->string('type')->default('');
            $table->string('status');

            $table->timestamp('started_at')->useCurrent();
            $table->timestamp('completed_at')->nullable();
            $table->unsignedInteger('records_processed')->default(0);
            $table->unsignedInteger('records_failed')->default(0);
            $table->text('error_message')->nullable();
            $table->json('summary')->nullable();

            $table->timestamps();

            $table->foreign('store_id')->references('id')->on('stores')->cascadeOnDelete();
            $table->foreign('platform_connection_id')->references('id')->on('platform_connections')->cascadeOnDelete();
            $table->index('entity_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sync_logs');
    }
};
