<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('product_variant_attribute_values', function (Blueprint $table) {
            $table->ulid('product_variant_id');
            $table->ulid('product_attribute_value_id');

            $table->timestamps();

            $table->foreign('product_variant_id', 'pvav_variant_id_foreign')
                ->references('id')->on('product_variants')->cascadeOnDelete();
            $table->foreign('product_attribute_value_id', 'pvav_attr_value_id_foreign')
                ->references('id')->on('product_attribute_values')->cascadeOnDelete();

            // Composite key both identifies the row and enforces uniqueness — no
            // separate unique index (its auto-generated name overflowed MySQL's
            // 64-char limit in the original migration).
            $table->primary(['product_variant_id', 'product_attribute_value_id'], 'pvav_primary');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('product_variant_attribute_values');
    }
};
