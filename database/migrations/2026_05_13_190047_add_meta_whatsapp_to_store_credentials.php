<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('store_credentials', function (Blueprint $table) {
            // Meta OAuth
            $table->string('meta_access_token')->nullable(); // encrypted
            $table->string('meta_business_account_id')->nullable();
            $table->string('meta_waba_id')->nullable(); // WhatsApp Business Account ID
            $table->text('meta_refresh_token')->nullable(); // encrypted (if using long-lived tokens)
            
            // WhatsApp Phone
            $table->string('whatsapp_phone_number')->nullable();
             // Meta's phone ID
            $table->string('whatsapp_display_name')->nullable(); // Display name in WhatsApp
            
            // Status
            $table->string('whatsapp_provider')->default('meta'); // meta, evolution
            $table->boolean('whatsapp_is_active')->default(false);
            $table->timestamp('whatsapp_connected_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('store_credentials', function (Blueprint $table) {
            $table->dropColumn([
                'meta_access_token',
                'meta_business_account_id',
                'meta_waba_id',
                'meta_refresh_token',
                'whatsapp_phone_number',
                'whatsapp_phone_number_id',
                'whatsapp_display_name',
                'whatsapp_provider',
                'whatsapp_is_active',
                'whatsapp_connected_at',
            ]);
        });
    }
};