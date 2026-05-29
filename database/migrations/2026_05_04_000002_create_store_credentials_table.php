<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('store_credentials', function (Blueprint $table): void {
            $table->char('id', 26)->primary();
            $table->char('store_id', 26)->unique();
            $table->foreign('store_id')->references('id')->on('stores')->cascadeOnDelete();

            $table->text('whatsapp_phone_number_id')->nullable();
            $table->text('whatsapp_access_token')->nullable();
            $table->text('whatsapp_webhook_verify_token')->nullable();
            $table->text('whatsapp_business_account_id')->nullable();

            $table->string('smtp_host')->nullable();
            $table->integer('smtp_port')->nullable();
            $table->string('smtp_username')->nullable();
            $table->text('smtp_password')->nullable();
            $table->string('smtp_from_name')->nullable();
            $table->string('smtp_from_email')->nullable();

            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('store_credentials');
    }
};
