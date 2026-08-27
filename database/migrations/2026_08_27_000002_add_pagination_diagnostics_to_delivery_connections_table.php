<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Diagnostics for a district/city sync, alongside the existing
 * last_city_sync_* columns — deliberately REAL columns, not JSON `settings`
 * keys, for the same reason those existing columns are: `settings` gets
 * wholesale-overwritten by every credential save (see
 * SenditConnectionController::store()/DeliveryConnectionController::storeOzon()),
 * which would silently wipe this diagnostic state on the next unrelated save.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('delivery_connections', function (Blueprint $table) {
            if (! Schema::hasColumn('delivery_connections', 'last_city_sync_pickup_district_id')) {
                $table->string('last_city_sync_pickup_district_id')->nullable()->after('last_city_sync_count');
            }

            if (! Schema::hasColumn('delivery_connections', 'last_city_sync_page_count')) {
                $table->unsignedInteger('last_city_sync_page_count')->nullable()->after('last_city_sync_pickup_district_id');
            }
        });
    }

    public function down(): void
    {
        Schema::table('delivery_connections', function (Blueprint $table) {
            foreach (['last_city_sync_page_count', 'last_city_sync_pickup_district_id'] as $column) {
                if (Schema::hasColumn('delivery_connections', $column)) {
                    $table->dropColumn($column);
                }
            }
        });
    }
};
