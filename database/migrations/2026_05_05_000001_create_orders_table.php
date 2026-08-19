<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('orders', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->ulid('store_id');
            $table->ulid('platform_connection_id')->nullable();

            $table->string('platform_order_id');
            $table->string('order_number');
            $table->string('status')->default('pending');

            // Unified fulfillment workflow (App\Enums\FulfillmentStatus). Plain
            // string, not an ENUM: the workflow gains states over time.
            $table->string('fulfillment_status', 32)->default('pending');
            $table->timestamp('fulfillment_updated_at')->nullable();

            $table->decimal('total', 10, 2);
            $table->char('currency', 3);

            $table->string('customer_name');
            $table->string('customer_email')->nullable();
            $table->string('customer_phone')->nullable();

            $table->json('items');
            $table->json('platform_data');
            $table->timestamp('synced_at')->nullable();
            $table->text('notes')->nullable();

            $table->timestamps();
            $table->softDeletes();

            // WhatsApp / AI confirmation pipeline.
            $table->string('confirmation_method')->nullable();
            $table->string('confirmation_tier')->nullable();
            $table->string('confirmation_status')->default('pending');
            $table->timestamp('whatsapp_sent_at')->nullable();
            $table->string('whatsapp_message_id')->nullable();
            $table->text('whatsapp_message_sent')->nullable();
            $table->boolean('ai_generated_message')->default(false);
            $table->text('ai_message_generated')->nullable();
            $table->decimal('ai_cost', 8, 4)->default(0);
            $table->string('n8n_execution_id')->nullable();
            $table->json('n8n_workflow_steps')->nullable();
            $table->text('customer_reply_raw')->nullable();
            $table->json('customer_reply_parsed')->nullable();
            $table->string('last_intent')->nullable();
            $table->decimal('last_confidence', 3, 2)->nullable();
            $table->json('whatsapp_interactions')->nullable();
            $table->timestamp('whatsapp_confirmed_at')->nullable();
            $table->timestamp('whatsapp_cancelled_at')->nullable();
            $table->unsignedInteger('confirmation_retry_count')->default(0);
            $table->timestamp('confirmation_last_retry_at')->nullable();

            // Who is working the order right now.
            $table->char('assigned_to', 26)->nullable();
            $table->timestamp('assigned_at')->nullable();

            $table->foreign('store_id')->references('id')->on('stores')->cascadeOnDelete();
            $table->foreign('platform_connection_id')->references('id')->on('platform_connections')->nullOnDelete();
            $table->foreign('assigned_to')->references('id')->on('users')->nullOnDelete();

            $table->unique(['platform_connection_id', 'platform_order_id']);
            $table->index(['store_id', 'status']);
            $table->index('customer_phone');
            $table->index(['store_id', 'fulfillment_status'], 'orders_store_fulfillment_index');
            $table->index(['store_id', 'assigned_to'], 'orders_store_assignee_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('orders');
    }
};
