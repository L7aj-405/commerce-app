<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('employees', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->ulid('organization_id');
            $table->ulid('store_id')->nullable(); // nullable: some employees work across the whole organization
            $table->ulid('user_id')->nullable(); // nullable: an employee may have no login at all

            $table->string('employee_code')->nullable();
            $table->string('first_name');
            $table->string('last_name')->nullable();
            // Denormalized display label — kept in sync by EmployeeService so
            // list/detail views never need to re-derive "first + last" or
            // fall back to the linked user's name inline everywhere.
            $table->string('display_name');
            $table->string('phone')->nullable();
            $table->string('email')->nullable();

            $table->string('role_type', 30)->nullable();
            $table->string('employment_status', 20)->default('active');

            $table->date('hired_at')->nullable();
            $table->date('left_at')->nullable();
            $table->text('notes')->nullable();

            $table->ulid('created_by')->nullable();

            $table->timestamps();
            $table->softDeletes();

            $table->foreign('organization_id')->references('id')->on('organizations')->cascadeOnDelete();
            $table->foreign('store_id')->references('id')->on('stores')->nullOnDelete();
            $table->foreign('user_id')->references('id')->on('users')->nullOnDelete();
            $table->foreign('created_by')->references('id')->on('users')->nullOnDelete();

            $table->index(['organization_id', 'employment_status']);
            $table->index(['organization_id', 'store_id']);
            $table->index(['organization_id', 'role_type']);
            $table->index(['organization_id', 'user_id']);
            $table->unique(['organization_id', 'employee_code'], 'employees_org_code_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('employees');
    }
};
