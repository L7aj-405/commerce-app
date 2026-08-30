<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('finance_expenses', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->ulid('organization_id');
            $table->ulid('store_id')->nullable(); // nullable: some expenses are organization-level
            $table->ulid('category_id');
            $table->ulid('vendor_id')->nullable();
            $table->ulid('recurring_expense_id')->nullable();

            $table->string('title');
            $table->text('description')->nullable();
            $table->decimal('amount', 12, 2);
            $table->string('currency', 3)->default('MAD');

            $table->date('expense_date');
            $table->date('due_date')->nullable();
            $table->timestamp('paid_at')->nullable();
            $table->string('status', 20)->default('unpaid'); // paid | unpaid | cancelled
            $table->string('payment_method', 20)->nullable(); // cash | bank_transfer | card | cheque | cod_settlement | other

            $table->string('reference')->nullable();
            $table->string('attachment_path')->nullable();

            // Where this expense came from, if not manually entered (e.g. the
            // recurring-expense generator). Kept generic for future sources.
            $table->string('source_type')->nullable();
            $table->string('source_id')->nullable();

            $table->ulid('created_by')->nullable();

            $table->timestamps();
            $table->softDeletes();

            $table->foreign('organization_id')->references('id')->on('organizations')->cascadeOnDelete();
            $table->foreign('store_id')->references('id')->on('stores')->nullOnDelete();
            $table->foreign('category_id')->references('id')->on('finance_expense_categories')->restrictOnDelete();
            $table->foreign('vendor_id')->references('id')->on('finance_vendors')->nullOnDelete();
            $table->foreign('recurring_expense_id')->references('id')->on('finance_recurring_expenses')->nullOnDelete();
            $table->foreign('created_by')->references('id')->on('users')->nullOnDelete();

            $table->index(['organization_id', 'status']);
            $table->index(['organization_id', 'expense_date']);
            $table->index(['organization_id', 'category_id']);
            $table->index(['organization_id', 'vendor_id']);
            $table->index(['organization_id', 'store_id']);
            $table->index(['recurring_expense_id', 'expense_date']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('finance_expenses');
    }
};
