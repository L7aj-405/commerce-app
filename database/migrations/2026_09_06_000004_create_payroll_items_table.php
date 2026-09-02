<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * One salary-due line per employee per payroll period. Calculating a period
 * creates/refreshes these rows — never a finance_transaction (see
 * App\Services\Payroll\PayrollService::calculate()). A finance_transaction
 * (salary_paid) is only ever booked when an item is actually PAID.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('payroll_items', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->ulid('organization_id');
            $table->ulid('payroll_period_id');
            $table->ulid('employee_id');
            $table->ulid('salary_profile_id')->nullable();

            $table->decimal('base_amount', 12, 2)->default(0);
            $table->decimal('bonus_amount', 12, 2)->default(0);
            $table->decimal('deduction_amount', 12, 2)->default(0);
            $table->decimal('advance_deduction_amount', 12, 2)->default(0);
            // net_amount = base + bonus - deduction - advance_deduction — stored
            // (not derived on read) so a paid item's net figure never silently
            // drifts if the calculation formula changes later.
            $table->decimal('net_amount', 12, 2)->default(0);
            $table->string('currency', 3)->default('MAD');

            $table->string('status', 20)->default('pending'); // pending | approved | paid | cancelled

            // How/when it was actually paid — only ever set once, by pay().
            $table->ulid('account_id')->nullable();
            $table->timestamp('paid_at')->nullable();
            $table->ulid('paid_by')->nullable();
            $table->string('reference')->nullable();

            $table->text('notes')->nullable();

            $table->timestamps();

            $table->foreign('organization_id')->references('id')->on('organizations')->cascadeOnDelete();
            $table->foreign('payroll_period_id', 'payroll_items_period_fk')->references('id')->on('payroll_periods')->cascadeOnDelete();
            $table->foreign('employee_id')->references('id')->on('employees')->restrictOnDelete();
            $table->foreign('salary_profile_id', 'payroll_items_profile_fk')->references('id')->on('employee_salary_profiles')->nullOnDelete();
            $table->foreign('account_id')->references('id')->on('finance_accounts')->nullOnDelete();
            $table->foreign('paid_by')->references('id')->on('users')->nullOnDelete();

            $table->index(['organization_id', 'status']);
            $table->index(['payroll_period_id', 'employee_id']);
            // A recalculation must update the existing row for an employee
            // already in this period, never insert a duplicate.
            $table->unique(['payroll_period_id', 'employee_id'], 'payroll_items_period_employee_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('payroll_items');
    }
};
