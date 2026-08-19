<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('store_roles', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->ulid('store_id');

            $table->string('name');
            $table->string('slug');
            $table->string('description')->nullable();

            // System roles are seeded automatically and cannot be deleted.
            // Locked roles (Administrator) additionally cannot have their permissions changed.
            $table->boolean('is_system')->default(false);
            $table->boolean('is_locked')->default(false);

            $table->json('permissions')->nullable();

            $table->timestamps();

            $table->foreign('store_id')->references('id')->on('stores')->cascadeOnDelete();
            $table->unique(['store_id', 'slug']);
            $table->index('store_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('store_roles');
    }
};
