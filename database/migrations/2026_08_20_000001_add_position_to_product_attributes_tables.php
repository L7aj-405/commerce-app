<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('product_attributes', function (Blueprint $table) {
            $table->unsignedInteger('position')->default(0)->after('slug');
        });

        Schema::table('product_attribute_values', function (Blueprint $table) {
            $table->unsignedInteger('position')->default(0)->after('slug');
        });
    }

    public function down(): void
    {
        Schema::table('product_attributes', function (Blueprint $table) {
            $table->dropColumn('position');
        });

        Schema::table('product_attribute_values', function (Blueprint $table) {
            $table->dropColumn('position');
        });
    }
};
