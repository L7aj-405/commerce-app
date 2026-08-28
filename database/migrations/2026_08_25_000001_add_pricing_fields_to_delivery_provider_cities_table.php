<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Ozon's real /cities response carries REF and three price fields per city
 * (DELIVERED-PRICE, RETURNED-PRICE, REFUSED-PRICE). Optional/provider-
 * specific — kept nullable so any future provider without them is unaffected.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('delivery_provider_cities', function (Blueprint $table) {
            if (! Schema::hasColumn('delivery_provider_cities', 'city_ref')) {
                $table->string('city_ref')->nullable()->after('city_name');
            }

            if (! Schema::hasColumn('delivery_provider_cities', 'delivered_price')) {
                $table->decimal('delivered_price', 10, 2)->nullable()->after('city_ref');
            }

            if (! Schema::hasColumn('delivery_provider_cities', 'returned_price')) {
                $table->decimal('returned_price', 10, 2)->nullable()->after('delivered_price');
            }

            if (! Schema::hasColumn('delivery_provider_cities', 'refused_price')) {
                $table->decimal('refused_price', 10, 2)->nullable()->after('returned_price');
            }
        });
    }

    public function down(): void
    {
        Schema::table('delivery_provider_cities', function (Blueprint $table) {
            foreach (['refused_price', 'returned_price', 'delivered_price', 'city_ref'] as $column) {
                if (Schema::hasColumn('delivery_provider_cities', $column)) {
                    $table->dropColumn($column);
                }
            }
        });
    }
};
