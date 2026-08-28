<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Generic (provider-agnostic) location fields, first needed by Sendit's
 * districts: `price` (delivery fee for this zone), `delais` (Sendit's own
 * delivery-delay estimate, kept as free text since its exact format isn't
 * documented), `is_pickup_district` (Sendit flags which districts may be
 * used as a pickup point — GET /districts/pickup-cities). All nullable so
 * Ozon's existing rows (which never populate them) are unaffected; Ozon's
 * OWN pricing fields (delivered_price etc., see the 2026_08_25 migration)
 * are untouched and not reused here on purpose — they mean something
 * different (post-delivery/return/refusal price, not a flat zone fee).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('delivery_provider_cities', function (Blueprint $table) {
            if (! Schema::hasColumn('delivery_provider_cities', 'price')) {
                $table->decimal('price', 10, 2)->nullable()->after('refused_price');
            }

            if (! Schema::hasColumn('delivery_provider_cities', 'delais')) {
                $table->string('delais')->nullable()->after('price');
            }

            if (! Schema::hasColumn('delivery_provider_cities', 'is_pickup_district')) {
                $table->boolean('is_pickup_district')->nullable()->after('delais');
            }
        });
    }

    public function down(): void
    {
        Schema::table('delivery_provider_cities', function (Blueprint $table) {
            foreach (['is_pickup_district', 'delais', 'price'] as $column) {
                if (Schema::hasColumn('delivery_provider_cities', $column)) {
                    $table->dropColumn($column);
                }
            }
        });
    }
};
