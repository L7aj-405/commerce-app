<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Catalogue of delivery carriers the platform knows how to integrate with.
 * Not tenant-scoped — this is a fixed, version-controlled list like
 * PlatformConnection's platform constants, just materialized as rows so
 * delivery_connections can foreign-key onto a real provider_code.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('delivery_providers', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->string('code')->unique();
            $table->string('name');
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        $now = now();

        DB::table('delivery_providers')->insert([
            [
                'id' => (string) Illuminate\Support\Str::ulid(),
                'code' => 'internal',
                'name' => 'Internal delivery',
                'is_active' => true,
                'created_at' => $now,
                'updated_at' => $now,
            ],
            [
                'id' => (string) Illuminate\Support\Str::ulid(),
                'code' => 'ozon',
                'name' => 'Ozon Express',
                'is_active' => true,
                'created_at' => $now,
                'updated_at' => $now,
            ],
        ]);
    }

    public function down(): void
    {
        Schema::dropIfExists('delivery_providers');
    }
};
