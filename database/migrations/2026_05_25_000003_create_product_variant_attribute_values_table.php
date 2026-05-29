<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('product_variant_attribute_values', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->ulid('product_variant_id');
            $table->ulid('product_attribute_value_id');
            $table->timestamps();

            $table->foreign('product_variant_id', 'pvav_variant_id_foreign')->references('id')->on('product_variants')->cascadeOnDelete();
            $table->foreign('product_attribute_value_id', 'pvav_attr_value_id_foreign')->references('id')->on('product_attribute_values')->cascadeOnDelete();
            $table->unique(['product_variant_id', 'product_attribute_value_id'], 'pv_av_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('product_variant_attribute_values');
    }
};
