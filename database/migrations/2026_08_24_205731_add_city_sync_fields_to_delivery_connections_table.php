<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('delivery_connections', function (Blueprint $table) {
            if (! Schema::hasColumn('delivery_connections', 'last_city_sync_at')) {
                $table->timestamp('last_city_sync_at')->nullable()->after('last_tested_at');
            }

            if (! Schema::hasColumn('delivery_connections', 'last_city_sync_error')) {
                $table->text('last_city_sync_error')->nullable()->after('last_city_sync_at');
            }

            if (! Schema::hasColumn('delivery_connections', 'last_city_sync_count')) {
                $table->unsignedInteger('last_city_sync_count')->default(0)->after('last_city_sync_error');
            }
        });
    }

    public function down(): void
    {
        Schema::table('delivery_connections', function (Blueprint $table) {
            if (Schema::hasColumn('delivery_connections', 'last_city_sync_count')) {
                $table->dropColumn('last_city_sync_count');
            }

            if (Schema::hasColumn('delivery_connections', 'last_city_sync_error')) {
                $table->dropColumn('last_city_sync_error');
            }

            if (Schema::hasColumn('delivery_connections', 'last_city_sync_at')) {
                $table->dropColumn('last_city_sync_at');
            }
        });
    }
};