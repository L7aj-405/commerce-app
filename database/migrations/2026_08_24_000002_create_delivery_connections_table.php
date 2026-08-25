<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A store's credentials for one delivery provider (e.g. its Ozon Express
 * account). Mirrors platform_connections: encrypted credentials, JSON
 * settings, a status/last_error pair for the connection-health UI.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('delivery_connections', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->ulid('store_id');

            // Denormalized for future agency-wide reporting only — never used
            // for tenant scoping (that stays store_id via BelongsToTenant).
            $table->ulid('organization_id')->nullable();

            $table->string('provider_code');
            $table->string('name');

            // JSON, encrypted: {customer_id, api_key} for Ozon.
            $table->text('credentials')->nullable();

            $table->json('settings')->nullable();

            // Authentication state ONLY — never touched by a city sync (see
            // the later add_city_sync_fields_to_delivery_connections_table
            // migration for the separate city-sync lifecycle columns).
            // connected|error, set exclusively by test(); disabled is the
            // untested-default and the explicit result of disconnect().
            $table->string('status', 16)->default('disabled'); // connected|error|disabled
            $table->timestamp('last_tested_at')->nullable();
            $table->text('last_error')->nullable();

            // Who set this connection up — resolved as the "actor" for
            // background jobs (e.g. scheduled tracking sync) that have no
            // request-bound user.
            $table->char('created_by', 26)->nullable();

            $table->timestamps();

            $table->foreign('store_id')->references('id')->on('stores')->cascadeOnDelete();
            $table->foreign('provider_code')->references('code')->on('delivery_providers')->cascadeOnUpdate();
            $table->foreign('created_by')->references('id')->on('users')->nullOnDelete();

            $table->unique(['store_id', 'provider_code']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('delivery_connections');
    }
};
