<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('finance_expense_categories', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->ulid('organization_id');

            $table->string('name');
            $table->string('slug');
            $table->string('group')->nullable();
            $table->string('color')->nullable();
            $table->string('icon')->nullable();
            $table->boolean('is_system')->default(false);
            $table->boolean('is_active')->default(true);

            $table->timestamps();

            $table->foreign('organization_id')->references('id')->on('organizations')->cascadeOnDelete();

            $table->unique(['organization_id', 'name']);
            $table->unique(['organization_id', 'slug']);
            $table->index(['organization_id', 'is_active']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('finance_expense_categories');
    }
};
