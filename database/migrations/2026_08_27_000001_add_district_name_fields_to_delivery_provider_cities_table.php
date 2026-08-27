<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Sendit's /districts rows carry TWO distinct name fields — `ville` (the
 * city, already stored as `city_name`) and `name` (the district within that
 * city — e.g. several districts can share one `ville`). The sync previously
 * only ever kept one of the two; `district_name` preserves `name` alongside
 * `city_name` so neither is lost. `name_arabic` is Sendit's documented
 * Arabic label, optional/provider-specific like the rest of this table.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('delivery_provider_cities', function (Blueprint $table) {
            if (! Schema::hasColumn('delivery_provider_cities', 'district_name')) {
                $table->string('district_name')->nullable()->after('city_name');
            }

            if (! Schema::hasColumn('delivery_provider_cities', 'name_arabic')) {
                $table->string('name_arabic')->nullable()->after('district_name');
            }
        });
    }

    public function down(): void
    {
        Schema::table('delivery_provider_cities', function (Blueprint $table) {
            foreach (['name_arabic', 'district_name'] as $column) {
                if (Schema::hasColumn('delivery_provider_cities', $column)) {
                    $table->dropColumn($column);
                }
            }
        });
    }
};
