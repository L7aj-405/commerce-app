<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('platform_connections', function (Blueprint $table) {
            $table->string('connection_method')->nullable()->after('platform');
            $table->string('webhook_status')->nullable()->after('webhook_secret');
            $table->timestamp('last_webhook_at')->nullable()->after('webhook_status');
        });
    }

    public function down(): void
    {
        Schema::table('platform_connections', function (Blueprint $table) {
            $table->dropColumn(['connection_method', 'webhook_status', 'last_webhook_at']);
        });
    }
};
