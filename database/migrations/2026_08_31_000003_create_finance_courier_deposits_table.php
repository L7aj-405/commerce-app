<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A cash handover from an internal delivery agent (employee/livreur) back
 * to the accountant, for a set of COD orders that agent delivered and
 * collected cash for. Unlike an external carrier settlement, the cash
 * itself is physically handed over — recorded here as `cash_received`
 * against the `expected_amount` (sum of the included orders' COD), with any
 * gap surfaced as a shortage/overage.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('finance_courier_deposits', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->ulid('organization_id');
            $table->ulid('store_id')->nullable();

            $table->ulid('courier_id'); // users.id — the internal delivery agent
            $table->date('deposit_date');

            $table->decimal('expected_amount', 12, 2)->default(0);
            $table->decimal('cash_received', 12, 2)->default(0);
            $table->decimal('difference', 12, 2)->default(0); // cash_received - expected_amount

            $table->ulid('account_id')->nullable(); // usually Cash
            $table->string('reference')->nullable();
            $table->text('notes')->nullable();

            $table->string('status', 20)->default('draft'); // draft | confirmed | cancelled
            $table->timestamp('confirmed_at')->nullable();
            $table->ulid('created_by')->nullable();

            $table->timestamps();

            $table->foreign('organization_id')->references('id')->on('organizations')->cascadeOnDelete();
            $table->foreign('store_id')->references('id')->on('stores')->nullOnDelete();
            $table->foreign('courier_id')->references('id')->on('users')->restrictOnDelete();
            $table->foreign('account_id')->references('id')->on('finance_accounts')->nullOnDelete();
            $table->foreign('created_by')->references('id')->on('users')->nullOnDelete();

            $table->index(['organization_id', 'status']);
            $table->index(['organization_id', 'deposit_date']);
            $table->index(['organization_id', 'courier_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('finance_courier_deposits');
    }
};
