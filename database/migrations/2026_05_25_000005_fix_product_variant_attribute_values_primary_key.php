<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('product_variant_attribute_values', function (Blueprint $table) {
            $table->dropPrimary();
            $table->dropColumn('id');
        });

        Schema::table('product_variant_attribute_values', function (Blueprint $table) {
            $table->primary(['product_variant_id', 'product_attribute_value_id'], 'pvav_primary');
        });
    }

    public function down(): void
    {
        Schema::table('product_variant_attribute_values', function (Blueprint $table) {
            $table->dropPrimary('pvav_primary');
            $table->ulid('id')->primary()->first();
        });
    }
};
