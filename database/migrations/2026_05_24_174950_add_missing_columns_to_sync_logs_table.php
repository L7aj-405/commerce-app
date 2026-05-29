<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('sync_logs', function (Blueprint $table) {
            // Add direction column if not exists
            if (!Schema::hasColumn('sync_logs', 'direction')) {
                $table->enum('direction', ['pull', 'push'])->default('pull')->after('platform_connection_id');
            }

            // Add action column if not exists
            if (!Schema::hasColumn('sync_logs', 'action')) {
                $table->enum('action', ['product', 'variant', 'stock', 'order', 'customer'])->nullable()->after('direction');
            }

            // Add entity_id column if not exists
            if (!Schema::hasColumn('sync_logs', 'entity_id')) {
                $table->string('entity_id')->nullable()->after('action');
                $table->index('entity_id');
            }

            // Add external_id column if not exists
            if (!Schema::hasColumn('sync_logs', 'external_id')) {
                $table->string('external_id')->nullable()->after('entity_id');
            }

            // Add status column if not exists
            if (!Schema::hasColumn('sync_logs', 'status')) {
                $table->enum('status', ['pending', 'success', 'failed'])->default('pending')->after('external_id');
            }

            // Add error_message column if not exists
            if (!Schema::hasColumn('sync_logs', 'error_message')) {
                $table->text('error_message')->nullable()->after('status');
            }

            // Add completed_at column if not exists
            if (!Schema::hasColumn('sync_logs', 'completed_at')) {
                $table->timestamp('completed_at')->nullable()->after('error_message');
            }
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('sync_logs', function (Blueprint $table) {
            $table->dropColumn([
                'direction',
                'action',
                'entity_id',
                'external_id',
                'status',
                'error_message',
                'completed_at',
            ]);
        });
    }
};