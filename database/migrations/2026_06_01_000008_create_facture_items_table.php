<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Frozen line-item snapshot taken at issue time — the invoice stays
        // correct even if the source order is later edited.
        Schema::create('facture_items', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->ulid('facture_id');
            $table->ulid('product_id')->nullable();

            $table->string('description');
            $table->string('sku')->nullable();

            $table->decimal('quantity', 12, 2)->default(1);
            $table->decimal('unit_price', 12, 2)->default(0);
            $table->decimal('discount_amount', 12, 2)->default(0);
            $table->decimal('tax_amount', 12, 2)->default(0);
            $table->decimal('line_total', 12, 2)->default(0);

            $table->timestamps();

            $table->foreign('facture_id')->references('id')->on('factures')->cascadeOnDelete();
            $table->index('facture_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('facture_items');
    }
};
