<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('store_credentials', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->ulid('store_id')->unique();

            // Meta / WhatsApp Cloud API (encrypted at the model layer).
            $table->text('whatsapp_phone_number_id')->nullable();
            $table->text('whatsapp_access_token')->nullable();
            $table->text('whatsapp_webhook_verify_token')->nullable();
            $table->text('whatsapp_business_account_id')->nullable();

            // SMTP.
            $table->string('smtp_host')->nullable();
            $table->integer('smtp_port')->nullable();
            $table->string('smtp_username')->nullable();
            $table->text('smtp_password')->nullable();
            $table->string('smtp_from_name')->nullable();
            $table->string('smtp_from_email')->nullable();

            // Evolution API (self-hosted WhatsApp).
            $table->string('whatsapp_evolution_instance_name')->nullable();
            $table->text('whatsapp_evolution_api_key')->nullable();
            $table->string('whatsapp_evolution_phone_number')->nullable();
            $table->timestamp('whatsapp_evolution_connected_at')->nullable();
            $table->boolean('whatsapp_evolution_is_active')->default(false);

            // AI assistant configuration.
            $table->string('whatsapp_ai_provider')->default('openai');
            $table->text('whatsapp_openai_api_key')->nullable();
            $table->text('whatsapp_anthropic_api_key')->nullable();
            $table->string('whatsapp_ai_model')->default('gpt-4o-mini');
            $table->boolean('whatsapp_ai_enabled')->default(true);
            $table->timestamp('whatsapp_ai_tested_at')->nullable();

            // Order-confirmation automation.
            $table->string('whatsapp_confirmation_tier')->default('simple');
            $table->decimal('whatsapp_auto_confirm_threshold', 10, 2)->default(100);
            $table->boolean('whatsapp_auto_confirm_enabled')->default(false);
            $table->string('whatsapp_brand_tone')->default('professional');
            $table->unsignedInteger('whatsapp_retry_first_delay')->default(2);
            $table->unsignedInteger('whatsapp_retry_second_delay')->default(5);
            $table->unsignedInteger('whatsapp_max_retries')->default(3);
            $table->boolean('whatsapp_send_discount_on_retry')->default(false);

            // Alerting.
            $table->boolean('whatsapp_alert_on_low_confidence')->default(true);
            $table->boolean('whatsapp_alert_on_complaint')->default(true);
            $table->boolean('whatsapp_alert_on_cancellation')->default(false);
            $table->string('whatsapp_alert_method')->default('dashboard');
            $table->json('whatsapp_support_languages')->nullable();

            // Setup / onboarding state.
            $table->string('whatsapp_setup_status')->default('pending');
            $table->text('whatsapp_setup_error')->nullable();
            $table->timestamp('whatsapp_setup_completed_at')->nullable();

            // Meta OAuth artifacts.
            $table->string('meta_access_token')->nullable();
            $table->string('meta_business_account_id')->nullable();
            $table->string('meta_waba_id')->nullable();
            $table->text('meta_refresh_token')->nullable();
            $table->string('whatsapp_phone_number')->nullable();
            $table->string('whatsapp_display_name')->nullable();
            $table->string('whatsapp_provider')->default('meta');
            $table->boolean('whatsapp_is_active')->default(false);
            $table->timestamp('whatsapp_connected_at')->nullable();

            $table->timestamps();

            $table->foreign('store_id')->references('id')->on('stores')->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('store_credentials');
    }
};
