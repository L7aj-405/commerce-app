<?php

declare(strict_types=1);

use App\Models\City;
use App\Support\Delivery\CityNameNormalizer;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Additive only — adds proper city references to `delivery_provider_city_fees`
 * on top of the free-text `city_name` it already had, per the "city fee
 * overrides must not be free text" fix. `city_name`/`provider_city_code`
 * stay as nullable snapshot/display columns (never the matching source of
 * truth once an id is present) — see
 * App\Services\Finance\FinanceDeliveryProviderFeeCalculator.
 *
 * - `provider_city_id` -> delivery_provider_cities.id (preferred for a
 *   provider-specific fee — matches exactly what Shipment.city_id already
 *   points to).
 * - `city_id` -> cities.id (the internal canonical Morocco city list,
 *   App\Models\City — the same one Order.shipping_city_id already uses).
 *
 * No existing row is touched destructively: both new columns are nullable,
 * and the best-effort backfill below only ever SETS city_id on a row that
 * doesn't have one yet, from an exact normalized-name match — it never
 * removes or overwrites `city_name`.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('delivery_provider_city_fees', function (Blueprint $table) {
            $table->ulid('city_id')->nullable()->after('delivery_provider_id');
            $table->ulid('provider_city_id')->nullable()->after('city_id');

            // city_name was required at create; it now doubles as a
            // snapshot/display value that may not exist for very old rows
            // touched some other way — nullable is the safe default going
            // forward, application code always fills it at write time.
            $table->string('city_name')->nullable()->change();

            $table->foreign('city_id', 'dp_city_fees_city_fk')->references('id')->on('cities')->nullOnDelete();
            $table->foreign('provider_city_id', 'dp_city_fees_provider_city_fk')->references('id')->on('delivery_provider_cities')->nullOnDelete();

            $table->index(['organization_id', 'delivery_provider_id', 'provider_city_id'], 'dp_city_fees_provider_city_idx');
            $table->index(['organization_id', 'delivery_provider_id', 'city_id'], 'dp_city_fees_city_idx');
        });

        $this->backfillCityIds();
    }

    /**
     * Best-effort, exact-normalized-name-only backfill — deliberately NOT
     * the fuzzy/alias matching DeliveryCityMappingResolver uses (this is a
     * one-time migration, not an interactive resolver; a wrong auto-match
     * on a fee record would misprice orders, so it only ever acts on an
     * unambiguous exact match). Any row that doesn't match anything keeps
     * working exactly as before via the city_name fallback tier.
     */
    private function backfillCityIds(): void
    {
        $cities = City::query()->where('country_code', 'MA')->where('is_active', true)->get(['id', 'name']);

        if ($cities->isEmpty()) {
            return;
        }

        $byNormalizedName = [];
        foreach ($cities as $city) {
            $byNormalizedName[CityNameNormalizer::normalize($city->name)] = $city->id;
        }

        DB::table('delivery_provider_city_fees')
            ->whereNull('city_id')
            ->whereNull('provider_city_id')
            ->whereNotNull('city_name')
            ->orderBy('id')
            ->each(function (object $row) use ($byNormalizedName): void {
                $normalized = CityNameNormalizer::normalize($row->city_name);

                if (isset($byNormalizedName[$normalized])) {
                    DB::table('delivery_provider_city_fees')->where('id', $row->id)->update(['city_id' => $byNormalizedName[$normalized]]);
                }
            });
    }

    public function down(): void
    {
        Schema::table('delivery_provider_city_fees', function (Blueprint $table) {
            $table->dropForeign('dp_city_fees_city_fk');
            $table->dropForeign('dp_city_fees_provider_city_fk');
            $table->dropColumn(['city_id', 'provider_city_id']);
            $table->string('city_name')->nullable(false)->change();
        });
    }
};
