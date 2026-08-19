<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('stock_ledger', function (Blueprint $table) {
            // Which variant this movement touched. Null for simple products.
            // nullOnDelete so removing a variant never erases its audit history.
            $table->ulid('variant_id')->nullable()->after('product_id');

            $table->foreign('variant_id')
                ->references('id')->on('product_variants')
                ->nullOnDelete();

            $table->index(['product_id', 'variant_id']);
        });
    }

    public function down(): void
    {
        Schema::table('stock_ledger', function (Blueprint $table) {
            $table->dropForeign(['variant_id']);
            $table->dropIndex(['product_id', 'variant_id']);
            $table->dropColumn('variant_id');
        });
    }
};
