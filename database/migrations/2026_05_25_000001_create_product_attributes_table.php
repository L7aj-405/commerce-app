<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('product_attributes', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->ulid('product_id');

            $table->string('name');
            $table->string('slug');

            $table->timestamps();

            $table->foreign('product_id')->references('id')->on('products')->cascadeOnDelete();
            $table->unique(['product_id', 'slug']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('product_attributes');
    }
};
