<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Salary HISTORY, not a single mutable row per employee — see
 * EmployeeSalaryService::createProfile(). Changing an employee's salary
 * closes the current active profile (effective_to = the day before the new
 * one starts) and inserts a new one; it never overwrites amounts in place.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('employee_salary_profiles', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->ulid('organization_id');
            $table->ulid('employee_id');

            $table->string('salary_type', 20)->default('monthly');
            $table->decimal('base_salary', 12, 2)->default(0);
            $table->string('currency', 3)->default('MAD');
            $table->string('payment_frequency', 20)->default('monthly');
            $table->unsignedTinyInteger('payment_day')->nullable(); // e.g. day-of-month or day-of-week, interpreted per payment_frequency

            $table->date('effective_from');
            $table->date('effective_to')->nullable();
            $table->boolean('is_active')->default(true);
            $table->text('notes')->nullable();

            $table->ulid('created_by')->nullable();

            $table->timestamps();

            $table->foreign('organization_id')->references('id')->on('organizations')->cascadeOnDelete();
            $table->foreign('employee_id')->references('id')->on('employees')->cascadeOnDelete();
            $table->foreign('created_by')->references('id')->on('users')->nullOnDelete();

            $table->index(['organization_id', 'employee_id', 'is_active'], 'salary_profiles_org_employee_active_idx');
            $table->index(['employee_id', 'effective_from'], 'salary_profiles_employee_effective_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('employee_salary_profiles');
    }
};
