<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('finance_accounts', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->ulid('organization_id');
            $table->ulid('store_id')->nullable(); // nullable: most accounts are organization-level

            $table->string('name');
            $table->string('type', 30); // cash | bank | card | cod_receivable | delivery_company | other
            $table->string('currency', 3)->default('MAD');
            $table->boolean('is_default')->default(false);
            $table->boolean('is_active')->default(true);

            $table->timestamps();

            $table->foreign('organization_id')->references('id')->on('organizations')->cascadeOnDelete();
            $table->foreign('store_id')->references('id')->on('stores')->nullOnDelete();

            $table->unique(['organization_id', 'name']);
            $table->index(['organization_id', 'type']);
            $table->index(['organization_id', 'is_active']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('finance_accounts');
    }
};
