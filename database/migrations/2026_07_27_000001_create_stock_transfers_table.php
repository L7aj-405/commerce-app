<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('stock_transfers', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->ulid('store_id');

            // BS-YYYYMMDD-0001 — the Bon de Sortie / exit-slip reference, unique per store.
            $table->string('reference');

            // Where the goods leave from (always a real warehouse).
            $table->ulid('source_warehouse_id');

            // Where they go. `destination_kind` keeps this open for the future:
            // a sibling warehouse (real stock move), or a team/external post
            // (goods physically leave — team notifications can hang off member_id).
            $table->string('destination_kind', 20)->default('warehouse'); // warehouse | team | external
            $table->ulid('destination_warehouse_id')->nullable();
            $table->ulid('destination_member_id')->nullable();  // future: notify / post to this member
            $table->string('destination_label')->nullable();    // free label for team/external targets

            // Who is accountable for the exit, and who recorded it.
            $table->ulid('responsible_member_id')->nullable();
            $table->ulid('created_by')->nullable();

            $table->string('status', 20)->default('completed'); // draft | completed | cancelled
            $table->date('transfer_date');
            $table->text('notes')->nullable();
            $table->unsignedInteger('total_quantity')->default(0);

            $table->timestamps();
            $table->softDeletes();

            $table->foreign('store_id')->references('id')->on('stores')->cascadeOnDelete();
            $table->foreign('source_warehouse_id')->references('id')->on('warehouses')->cascadeOnDelete();
            $table->foreign('destination_warehouse_id')->references('id')->on('warehouses')->nullOnDelete();
            $table->foreign('destination_member_id')->references('id')->on('users')->nullOnDelete();
            $table->foreign('responsible_member_id')->references('id')->on('users')->nullOnDelete();
            $table->foreign('created_by')->references('id')->on('users')->nullOnDelete();

            $table->unique(['store_id', 'reference']);
            $table->index(['store_id', 'status']);
            $table->index('source_warehouse_id');
            $table->index('transfer_date');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('stock_transfers');
    }
};
