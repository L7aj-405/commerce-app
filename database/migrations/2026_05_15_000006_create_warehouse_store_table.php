<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('warehouse_store', function (Blueprint $table) {
            $table->id();
            $table->string('warehouse_id');
            $table->string('store_id');
            $table->boolean('is_primary')->default(false);
            $table->integer('priority')->default(0);

            $table->timestamps();

            $table->foreign('warehouse_id')->references('id')->on('warehouses')->cascadeOnDelete();
            $table->foreign('store_id')->references('id')->on('stores')->cascadeOnDelete();

            $table->unique(['warehouse_id', 'store_id']);
            $table->index('store_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('warehouse_store');
    }
};
