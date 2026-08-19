<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('pos_devices', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->ulid('store_id');

            $table->string('device_name');
            $table->string('device_id')->unique();

            $table->enum('status', ['active', 'inactive', 'offline'])->default('inactive');

            $table->string('receipt_printer_id')->nullable();
            $table->string('thermal_paper_width')->default('80mm');
            $table->boolean('auto_print_receipts')->default(true);

            $table->ulid('current_session_id')->nullable();

            $table->timestamps();

            $table->foreign('store_id')->references('id')->on('stores')->cascadeOnDelete();
            $table->foreign('current_session_id')->references('id')->on('pos_sessions')->nullOnDelete();

            $table->index(['store_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('pos_devices');
    }
};
