<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('product_attribute_values', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->ulid('attribute_id');
            $table->string('value');
            $table->string('slug');
            $table->timestamps();

            $table->foreign('attribute_id')->references('id')->on('product_attributes')->cascadeOnDelete();
            $table->unique(['attribute_id', 'slug']);
            $table->index('attribute_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('product_attribute_values');
    }
};
