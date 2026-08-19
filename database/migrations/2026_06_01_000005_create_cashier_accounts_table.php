<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('cashier_accounts', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->ulid('store_id');
            $table->char('user_id', 26);

            $table->text('pin_code');

            $table->enum('status', ['active', 'inactive', 'suspended'])->default('active');

            $table->decimal('daily_limit', 12, 2)->nullable();
            $table->boolean('can_give_discounts')->default(false);
            $table->decimal('max_discount_percent', 5, 2)->default(0);

            $table->timestamp('last_login_at')->nullable();
            $table->timestamp('locked_until')->nullable();
            $table->unsignedInteger('failed_login_attempts')->default(0);

            $table->timestamps();

            $table->foreign('store_id')->references('id')->on('stores')->cascadeOnDelete();
            $table->foreign('user_id')->references('id')->on('users')->cascadeOnDelete();

            $table->unique(['store_id', 'user_id']);
            $table->index('status');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('cashier_accounts');
    }
};
