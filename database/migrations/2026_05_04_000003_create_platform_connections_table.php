<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('platform_connections', function (Blueprint $table): void {
            $table->char('id', 26)->primary();
            $table->char('store_id', 26);
            $table->foreign('store_id')->references('id')->on('stores')->cascadeOnDelete();

            $table->string('platform');
            $table->string('label')->nullable();
            $table->string('status')->default('pending');
            $table->boolean('is_syncing')->default(false);
            $table->timestamp('last_synced_at')->nullable();
            $table->text('last_sync_error')->nullable();
            $table->unsignedInteger('synced_products_count')->default(0);
            $table->unsignedInteger('synced_orders_count')->default(0);

            $table->text('api_url')->nullable();
            $table->text('consumer_key')->nullable();
            $table->text('consumer_secret')->nullable();
            $table->text('access_token')->nullable();
            $table->string('shop_domain')->nullable();
            $table->text('webhook_secret')->nullable();

            $table->json('settings')->nullable();
            $table->json('metadata')->nullable();

            $table->timestamps();
            $table->softDeletes();

            $table->unique(['store_id', 'platform']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('platform_connections');
    }
};
