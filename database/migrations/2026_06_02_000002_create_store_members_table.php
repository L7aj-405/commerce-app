<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('store_members', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->ulid('store_id');
            $table->char('user_id', 26);

            $table->enum('role', ['store_admin', 'manager', 'cashier'])->default('cashier');
            $table->ulid('store_role_id')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamp('joined_at')->nullable();

            $table->timestamps();

            $table->foreign('store_id')->references('id')->on('stores')->cascadeOnDelete();
            $table->foreign('user_id')->references('id')->on('users')->cascadeOnDelete();
            $table->foreign('store_role_id')->references('id')->on('store_roles')->nullOnDelete();

            $table->unique(['store_id', 'user_id']);
            $table->index(['store_id', 'is_active']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('store_members');
    }
};
