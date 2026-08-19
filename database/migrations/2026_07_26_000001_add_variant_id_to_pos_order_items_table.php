<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('pos_order_items', function (Blueprint $table) {
            // Which specific variant sold. Null for simple products. Kept nullable
            // (not cascadeOnDelete) so deleting a variant never wipes sale history —
            // the line still carries product_name / product_sku for the receipt.
            $table->ulid('variant_id')->nullable()->after('product_id');

            $table->foreign('variant_id')
                ->references('id')->on('product_variants')
                ->nullOnDelete();

            $table->index('variant_id');
        });
    }

    public function down(): void
    {
        Schema::table('pos_order_items', function (Blueprint $table) {
            $table->dropForeign(['variant_id']);
            $table->dropIndex(['variant_id']);
            $table->dropColumn('variant_id');
        });
    }
};
