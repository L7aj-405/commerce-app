<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('sync_logs', function (Blueprint $table) {
            if (!Schema::hasColumn('sync_logs', 'store_id')) {
                $table->char('store_id', 26)->nullable()->after('id');
                $table->foreign('store_id')->references('id')->on('stores')->cascadeOnDelete();
            }

            if (!Schema::hasColumn('sync_logs', 'platform')) {
                $table->string('platform', 50)->nullable()->after('store_id');
            }

            if (!Schema::hasColumn('sync_logs', 'direction')) {
                $table->enum('direction', ['pull', 'push'])->default('pull')->after('platform');
            }

            if (!Schema::hasColumn('sync_logs', 'action')) {
                $table->enum('action', ['product', 'variant', 'stock', 'order', 'customer'])->nullable()->after('direction');
            }

            if (!Schema::hasColumn('sync_logs', 'entity_id')) {
                $table->char('entity_id', 26)->nullable()->after('action');
                $table->index('entity_id');
            }

            if (!Schema::hasColumn('sync_logs', 'external_id')) {
                $table->string('external_id')->nullable()->after('entity_id');
            }
        });
    }

    public function down(): void
    {
        Schema::table('sync_logs', function (Blueprint $table) {
            $table->dropForeign(['store_id']);
            $table->dropColumn(['store_id', 'platform', 'direction', 'action', 'entity_id', 'external_id']);
        });
    }
};
