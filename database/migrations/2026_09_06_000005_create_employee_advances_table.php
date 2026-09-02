<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('employee_advances', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->ulid('organization_id');
            $table->ulid('employee_id');

            $table->decimal('amount', 12, 2);
            $table->string('currency', 3)->default('MAD');
            $table->date('advance_date');
            $table->string('status', 20)->default('pending'); // pending | approved | paid | deducted | cancelled

            $table->ulid('account_id')->nullable();
            $table->text('reason')->nullable();

            $table->ulid('approved_by')->nullable();
            $table->timestamp('approved_at')->nullable();
            $table->ulid('paid_by')->nullable();
            $table->timestamp('paid_at')->nullable();

            // Set once the advance has been deducted from a payroll item —
            // links back to exactly which payroll run absorbed it, and is
            // what stops it from ever being deducted a second time.
            $table->ulid('deducted_in_payroll_item_id')->nullable();

            $table->text('notes')->nullable();

            $table->timestamps();

            $table->foreign('organization_id')->references('id')->on('organizations')->cascadeOnDelete();
            $table->foreign('employee_id')->references('id')->on('employees')->restrictOnDelete();
            $table->foreign('account_id')->references('id')->on('finance_accounts')->nullOnDelete();
            $table->foreign('approved_by')->references('id')->on('users')->nullOnDelete();
            $table->foreign('paid_by')->references('id')->on('users')->nullOnDelete();
            $table->foreign('deducted_in_payroll_item_id', 'employee_advances_deducted_item_fk')->references('id')->on('payroll_items')->nullOnDelete();

            $table->index(['organization_id', 'status']);
            $table->index(['organization_id', 'employee_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('employee_advances');
    }
};
