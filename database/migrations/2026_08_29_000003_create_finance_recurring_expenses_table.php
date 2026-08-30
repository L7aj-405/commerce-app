<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('finance_recurring_expenses', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->ulid('organization_id');
            $table->ulid('store_id')->nullable();
            $table->ulid('category_id');
            $table->ulid('vendor_id')->nullable();

            $table->string('title');
            $table->text('description')->nullable();
            $table->decimal('amount', 12, 2);
            $table->string('currency', 3)->default('MAD');

            $table->string('frequency', 20); // weekly | monthly | quarterly | yearly
            $table->date('starts_at');
            $table->date('next_due_at');
            $table->unsignedSmallInteger('reminder_days_before')->default(7);
            $table->boolean('auto_create_expense')->default(true);
            $table->string('generated_expense_status', 20)->default('unpaid'); // paid | unpaid
            $table->string('status', 20)->default('active'); // active | paused | cancelled
            $table->timestamp('last_generated_at')->nullable();
            $table->text('notes')->nullable();

            $table->timestamps();
            $table->softDeletes();

            $table->foreign('organization_id')->references('id')->on('organizations')->cascadeOnDelete();
            $table->foreign('store_id')->references('id')->on('stores')->nullOnDelete();
            $table->foreign('category_id')->references('id')->on('finance_expense_categories')->restrictOnDelete();
            $table->foreign('vendor_id')->references('id')->on('finance_vendors')->nullOnDelete();

            $table->index(['organization_id', 'status']);
            $table->index(['organization_id', 'next_due_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('finance_recurring_expenses');
    }
};
