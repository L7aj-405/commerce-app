<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('stores', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->ulid('user_id');

            $table->string('name');
            $table->string('slug')->unique();
            $table->text('description')->nullable();

            $table->string('type');
            $table->string('business_type')->nullable();
            $table->string('status')->default('active');

            $table->char('currency', 3)->default('MAD');
            $table->char('country', 2)->default('MA');

            $table->text('address')->nullable();
            $table->string('email')->nullable();
            $table->string('phone')->nullable();
            $table->string('logo')->nullable();
            $table->string('cover_image')->nullable();

            $table->json('settings')->nullable();
            $table->json('business_rules')->nullable();
            $table->json('metadata')->nullable();

            $table->timestamps();
            $table->softDeletes();

            $table->foreign('user_id')->references('id')->on('users')->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('stores');
    }
};
