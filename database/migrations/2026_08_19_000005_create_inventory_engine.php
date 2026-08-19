<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('cities', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->char('country_code', 2)->default('MA');
            $table->string('code', 80)->unique();
            $table->string('name', 120);
            $table->string('region', 120)->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
            $table->index(['country_code', 'is_active'], 'cities_country_active_idx');
        });

        $moroccoCities = [
            ['casablanca','Casablanca','Casablanca-Settat'], ['rabat','Rabat','Rabat-Salé-Kénitra'],
            ['marrakech','Marrakech','Marrakech-Safi'], ['tanger','Tanger','Tanger-Tétouan-Al Hoceïma'],
            ['agadir','Agadir','Souss-Massa'], ['fes','Fès','Fès-Meknès'], ['meknes','Meknès','Fès-Meknès'],
            ['oujda','Oujda','Oriental'], ['kenitra','Kénitra','Rabat-Salé-Kénitra'], ['sale','Salé','Rabat-Salé-Kénitra'],
            ['temara','Témara','Rabat-Salé-Kénitra'], ['mohammedia','Mohammedia','Casablanca-Settat'],
            ['el-jadida','El Jadida','Casablanca-Settat'], ['settat','Settat','Casablanca-Settat'],
            ['safi','Safi','Marrakech-Safi'], ['essaouira','Essaouira','Marrakech-Safi'], ['beni-mellal','Béni Mellal','Béni Mellal-Khénifra'],
            ['khouribga','Khouribga','Béni Mellal-Khénifra'], ['tetouan','Tétouan','Tanger-Tétouan-Al Hoceïma'],
            ['larache','Larache','Tanger-Tétouan-Al Hoceïma'], ['al-hoceima','Al Hoceïma','Tanger-Tétouan-Al Hoceïma'],
            ['nador','Nador','Oriental'], ['berkane','Berkane','Oriental'], ['taza','Taza','Fès-Meknès'],
            ['ifrane','Ifrane','Fès-Meknès'], ['errachidia','Errachidia','Drâa-Tafilalet'], ['ouarzazate','Ouarzazate','Drâa-Tafilalet'],
            ['guelmim','Guelmim','Guelmim-Oued Noun'], ['laayoune','Laâyoune','Laâyoune-Sakia El Hamra'], ['dakhla','Dakhla','Dakhla-Oued Ed-Dahab'],
            ['berrechid','Berrechid','Casablanca-Settat'], ['bouskoura','Bouskoura','Casablanca-Settat'],
            ['benguerir','Benguerir','Marrakech-Safi'], ['khemisset','Khémisset','Rabat-Salé-Kénitra'], ['sidi-kacem','Sidi Kacem','Rabat-Salé-Kénitra'],
        ];
        $now = now();
        DB::table('cities')->insert(array_map(fn (array $city): array => [
            'id' => (string) Str::ulid(), 'country_code' => 'MA', 'code' => 'MA-' . strtoupper($city[0]),
            'name' => $city[1], 'region' => $city[2], 'is_active' => true, 'created_at' => $now, 'updated_at' => $now,
        ], $moroccoCities));

        Schema::create('inventory_items', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->ulid('organization_id');
            $table->string('sku', 190);
            $table->string('barcode', 190)->nullable();
            $table->string('name');
            $table->string('unit', 32)->default('unit');
            $table->boolean('track_inventory')->default(true);
            $table->unsignedInteger('reorder_level')->default(10);
            $table->boolean('is_active')->default(true);
            $table->json('metadata')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->foreign('organization_id', 'inv_item_org_fk')->references('id')->on('organizations')->cascadeOnDelete();
            $table->unique(['organization_id', 'sku'], 'inv_item_org_sku_uq');
            $table->index(['organization_id', 'is_active'], 'inv_item_org_active_idx');
        });

        Schema::create('product_inventory_links', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->ulid('organization_id');
            $table->ulid('inventory_item_id');
            $table->ulid('product_id');
            $table->decimal('units_per_sale', 12, 4)->default(1);
            $table->timestamps();

            $table->foreign('organization_id', 'pil_org_fk')->references('id')->on('organizations')->cascadeOnDelete();
            $table->foreign('inventory_item_id', 'pil_item_fk')->references('id')->on('inventory_items')->cascadeOnDelete();
            $table->foreign('product_id', 'pil_product_fk')->references('id')->on('products')->cascadeOnDelete();
            $table->unique('product_id', 'pil_product_uq');
            $table->index(['organization_id', 'inventory_item_id'], 'pil_org_item_idx');
        });

        Schema::create('variant_inventory_links', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->ulid('organization_id');
            $table->ulid('inventory_item_id');
            $table->ulid('product_variant_id');
            $table->decimal('units_per_sale', 12, 4)->default(1);
            $table->timestamps();

            $table->foreign('organization_id', 'vil_org_fk')->references('id')->on('organizations')->cascadeOnDelete();
            $table->foreign('inventory_item_id', 'vil_item_fk')->references('id')->on('inventory_items')->cascadeOnDelete();
            $table->foreign('product_variant_id', 'vil_variant_fk')->references('id')->on('product_variants')->cascadeOnDelete();
            $table->unique('product_variant_id', 'vil_variant_uq');
            $table->index(['organization_id', 'inventory_item_id'], 'vil_org_item_idx');
        });

        Schema::create('warehouse_inventory_balances', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->ulid('organization_id');
            $table->ulid('inventory_item_id');
            $table->ulid('warehouse_id');
            $table->integer('on_hand')->default(0);
            $table->unsignedInteger('reserved')->default(0);
            $table->unsignedInteger('transfer_reserved')->default(0);
            $table->unsignedInteger('reorder_level')->nullable();
            $table->timestamps();

            $table->foreign('organization_id', 'wib_org_fk')->references('id')->on('organizations')->cascadeOnDelete();
            $table->foreign('inventory_item_id', 'wib_item_fk')->references('id')->on('inventory_items')->cascadeOnDelete();
            $table->foreign('warehouse_id', 'wib_wh_fk')->references('id')->on('warehouses')->cascadeOnDelete();
            $table->unique(['inventory_item_id', 'warehouse_id'], 'wib_item_wh_uq');
            $table->index(['organization_id', 'warehouse_id'], 'wib_org_wh_idx');
        });

        Schema::create('warehouse_service_areas', function (Blueprint $table): void {
            $table->ulid('warehouse_id');
            $table->ulid('city_id');
            $table->unsignedSmallInteger('priority')->default(100);
            $table->boolean('is_active')->default(true);
            $table->json('settings')->nullable();
            $table->timestamps();

            $table->foreign('warehouse_id', 'wsa_wh_fk')->references('id')->on('warehouses')->cascadeOnDelete();
            $table->foreign('city_id', 'wsa_city_fk')->references('id')->on('cities')->cascadeOnDelete();
            $table->unique(['warehouse_id', 'city_id'], 'wsa_wh_city_uq');
            $table->index(['city_id', 'priority', 'is_active'], 'wsa_city_priority_idx');
        });

        Schema::create('inventory_allocations', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->ulid('organization_id');
            $table->ulid('store_id')->nullable();
            $table->nullableUlidMorphs('source');
            $table->ulid('city_id')->nullable();
            $table->ulid('warehouse_id')->nullable();
            $table->string('status', 32)->default('pending');
            $table->string('strategy', 40)->nullable();
            $table->decimal('fill_ratio', 5, 4)->default(0);
            $table->timestamp('allocated_at')->nullable();
            $table->timestamp('released_at')->nullable();
            $table->timestamp('consumed_at')->nullable();
            $table->text('notes')->nullable();
            $table->timestamps();

            $table->foreign('organization_id', 'ia_org_fk')->references('id')->on('organizations')->cascadeOnDelete();
            $table->foreign('store_id', 'ia_store_fk')->references('id')->on('stores')->nullOnDelete();
            $table->foreign('city_id', 'ia_city_fk')->references('id')->on('cities')->nullOnDelete();
            $table->foreign('warehouse_id', 'ia_wh_fk')->references('id')->on('warehouses')->nullOnDelete();
            $table->unique(['source_type', 'source_id'], 'ia_source_uq');
            $table->index(['organization_id', 'status'], 'ia_org_status_idx');
        });

        Schema::create('inventory_transfers', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->ulid('organization_id');
            $table->ulid('source_warehouse_id');
            $table->ulid('destination_warehouse_id');
            $table->string('reference', 80);
            $table->string('status', 24)->default('requested');
            $table->string('reason', 40)->default('replenishment');
            $table->ulid('requested_by')->nullable();
            $table->timestamp('approved_at')->nullable();
            $table->timestamp('shipped_at')->nullable();
            $table->timestamp('received_at')->nullable();
            $table->text('notes')->nullable();
            $table->timestamps();

            $table->foreign('organization_id', 'it_org_fk')->references('id')->on('organizations')->cascadeOnDelete();
            $table->foreign('source_warehouse_id', 'it_src_wh_fk')->references('id')->on('warehouses')->cascadeOnDelete();
            $table->foreign('destination_warehouse_id', 'it_dst_wh_fk')->references('id')->on('warehouses')->cascadeOnDelete();
            $table->foreign('requested_by', 'it_user_fk')->references('id')->on('users')->nullOnDelete();
            $table->unique(['organization_id', 'reference'], 'it_org_ref_uq');
            $table->index(['organization_id', 'status'], 'it_org_status_idx');
        });

        Schema::create('inventory_transfer_items', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->ulid('inventory_transfer_id');
            $table->ulid('inventory_item_id');
            $table->unsignedInteger('quantity');
            $table->unsignedInteger('received_quantity')->default(0);
            $table->ulid('allocation_id')->nullable();
            $table->timestamps();

            $table->foreign('inventory_transfer_id', 'iti_transfer_fk')->references('id')->on('inventory_transfers')->cascadeOnDelete();
            $table->foreign('inventory_item_id', 'iti_item_fk')->references('id')->on('inventory_items')->cascadeOnDelete();
            $table->foreign('allocation_id', 'iti_alloc_fk')->references('id')->on('inventory_allocations')->nullOnDelete();
            $table->unique(['inventory_transfer_id', 'inventory_item_id'], 'iti_transfer_item_uq');
        });

        Schema::create('inventory_reservations', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->ulid('organization_id');
            $table->ulid('allocation_id');
            $table->ulid('inventory_item_id');
            $table->ulid('warehouse_id');
            $table->unsignedInteger('requested_quantity');
            $table->unsignedInteger('reserved_quantity')->default(0);
            $table->unsignedInteger('shortage_quantity')->default(0);
            $table->string('status', 24)->default('active');
            $table->ulid('inventory_transfer_id')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamps();

            $table->foreign('organization_id', 'ir_org_fk')->references('id')->on('organizations')->cascadeOnDelete();
            $table->foreign('allocation_id', 'ir_alloc_fk')->references('id')->on('inventory_allocations')->cascadeOnDelete();
            $table->foreign('inventory_item_id', 'ir_item_fk')->references('id')->on('inventory_items')->cascadeOnDelete();
            $table->foreign('warehouse_id', 'ir_wh_fk')->references('id')->on('warehouses')->cascadeOnDelete();
            $table->foreign('inventory_transfer_id', 'ir_transfer_fk')->references('id')->on('inventory_transfers')->nullOnDelete();
            $table->unique(['allocation_id', 'inventory_item_id'], 'ir_alloc_item_uq');
            $table->index(['organization_id', 'status'], 'ir_org_status_idx');
        });

        Schema::create('inventory_ledger_entries', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->ulid('organization_id');
            $table->ulid('inventory_item_id');
            $table->ulid('warehouse_id');
            $table->string('event_type', 40);
            $table->integer('on_hand_delta')->default(0);
            $table->integer('reserved_delta')->default(0);
            $table->integer('transfer_reserved_delta')->default(0);
            $table->integer('on_hand_after');
            $table->unsignedInteger('reserved_after');
            $table->unsignedInteger('transfer_reserved_after');
            $table->nullableUlidMorphs('source');
            $table->string('reference', 120)->nullable();
            $table->text('notes')->nullable();
            $table->ulid('actor_user_id')->nullable();
            $table->timestamps();

            $table->foreign('organization_id', 'ile_org_fk')->references('id')->on('organizations')->cascadeOnDelete();
            $table->foreign('inventory_item_id', 'ile_item_fk')->references('id')->on('inventory_items')->cascadeOnDelete();
            $table->foreign('warehouse_id', 'ile_wh_fk')->references('id')->on('warehouses')->cascadeOnDelete();
            $table->foreign('actor_user_id', 'ile_user_fk')->references('id')->on('users')->nullOnDelete();
            $table->index(['organization_id', 'inventory_item_id', 'created_at'], 'ile_org_item_time_idx');
            $table->index(['warehouse_id', 'created_at'], 'ile_wh_time_idx');
        });

        Schema::table('orders', function (Blueprint $table): void {
            $table->ulid('shipping_city_id')->nullable()->after('customer_phone');
            $table->text('confirmed_shipping_address')->nullable()->after('shipping_city_id');
            $table->foreign('shipping_city_id', 'orders_ship_city_fk')->references('id')->on('cities')->nullOnDelete();
        });

        Schema::table('pos_orders', function (Blueprint $table): void {
            $table->ulid('shipping_city_id')->nullable()->after('delivery_address');
            $table->foreign('shipping_city_id', 'pos_orders_ship_city_fk')->references('id')->on('cities')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('pos_orders', function (Blueprint $table): void {
            $table->dropForeign('pos_orders_ship_city_fk');
            $table->dropColumn('shipping_city_id');
        });
        Schema::table('orders', function (Blueprint $table): void {
            $table->dropForeign('orders_ship_city_fk');
            $table->dropColumn(['shipping_city_id', 'confirmed_shipping_address']);
        });

        Schema::dropIfExists('inventory_ledger_entries');
        Schema::dropIfExists('inventory_reservations');
        Schema::dropIfExists('inventory_transfer_items');
        Schema::dropIfExists('inventory_transfers');
        Schema::dropIfExists('inventory_allocations');
        Schema::dropIfExists('warehouse_service_areas');
        Schema::dropIfExists('warehouse_inventory_balances');
        Schema::dropIfExists('variant_inventory_links');
        Schema::dropIfExists('product_inventory_links');
        Schema::dropIfExists('inventory_items');
        Schema::dropIfExists('cities');
    }
};
