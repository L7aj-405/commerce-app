<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('store_credentials', function (Blueprint $table): void {
            $table->string('whatsapp_evolution_instance_name')->nullable();
            $table->text('whatsapp_evolution_api_key')->nullable();
            $table->string('whatsapp_evolution_phone_number')->nullable();
            $table->timestamp('whatsapp_evolution_connected_at')->nullable();
            $table->boolean('whatsapp_evolution_is_active')->default(false);

            $table->string('whatsapp_ai_provider')->default('openai');
            $table->text('whatsapp_openai_api_key')->nullable();
            $table->text('whatsapp_anthropic_api_key')->nullable();
            $table->string('whatsapp_ai_model')->default('gpt-4o-mini');
            $table->boolean('whatsapp_ai_enabled')->default(true);
            $table->timestamp('whatsapp_ai_tested_at')->nullable();

            $table->string('whatsapp_confirmation_tier')->default('simple');
            $table->decimal('whatsapp_auto_confirm_threshold', 10, 2)->default(100.00);
            $table->boolean('whatsapp_auto_confirm_enabled')->default(false);
            $table->string('whatsapp_brand_tone')->default('professional');

            $table->unsignedInteger('whatsapp_retry_first_delay')->default(2);
            $table->unsignedInteger('whatsapp_retry_second_delay')->default(5);
            $table->unsignedInteger('whatsapp_max_retries')->default(3);
            $table->boolean('whatsapp_send_discount_on_retry')->default(false);

            $table->boolean('whatsapp_alert_on_low_confidence')->default(true);
            $table->boolean('whatsapp_alert_on_complaint')->default(true);
            $table->boolean('whatsapp_alert_on_cancellation')->default(false);
            $table->string('whatsapp_alert_method')->default('dashboard');

            $table->json('whatsapp_support_languages')->nullable();

            $table->string('whatsapp_setup_status')->default('pending');
            $table->text('whatsapp_setup_error')->nullable();
            $table->timestamp('whatsapp_setup_completed_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('store_credentials', function (Blueprint $table): void {
            $table->dropColumn([
                'whatsapp_evolution_instance_name',
                'whatsapp_evolution_api_key',
                'whatsapp_evolution_phone_number',
                'whatsapp_evolution_connected_at',
                'whatsapp_evolution_is_active',
                'whatsapp_ai_provider',
                'whatsapp_openai_api_key',
                'whatsapp_anthropic_api_key',
                'whatsapp_ai_model',
                'whatsapp_ai_enabled',
                'whatsapp_ai_tested_at',
                'whatsapp_confirmation_tier',
                'whatsapp_auto_confirm_threshold',
                'whatsapp_auto_confirm_enabled',
                'whatsapp_brand_tone',
                'whatsapp_retry_first_delay',
                'whatsapp_retry_second_delay',
                'whatsapp_max_retries',
                'whatsapp_send_discount_on_retry',
                'whatsapp_alert_on_low_confidence',
                'whatsapp_alert_on_complaint',
                'whatsapp_alert_on_cancellation',
                'whatsapp_alert_method',
                'whatsapp_support_languages',
                'whatsapp_setup_status',
                'whatsapp_setup_error',
                'whatsapp_setup_completed_at',
            ]);
        });
    }
};
