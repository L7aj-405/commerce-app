<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Seeds Sendit into the delivery_providers catalogue — required before any
 * delivery_connections/delivery_provider_cities/shipments row can carry
 * provider_code='sendit' (that column foreign-keys onto this table's code).
 */
return new class extends Migration
{
    public function up(): void
    {
        if (DB::table('delivery_providers')->where('code', 'sendit')->exists()) {
            return;
        }

        DB::table('delivery_providers')->insert([
            'id' => (string) Str::ulid(),
            'code' => 'sendit',
            'name' => 'Sendit',
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    public function down(): void
    {
        DB::table('delivery_providers')->where('code', 'sendit')->delete();
    }
};
