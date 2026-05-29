<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('orders', function (Blueprint $table): void {
            if (!Schema::hasColumn('orders', 'confirmation_method')) {
                $table->string('confirmation_method')->nullable();
            }
            if (!Schema::hasColumn('orders', 'confirmation_tier')) {
                $table->string('confirmation_tier')->nullable();
            }
            if (!Schema::hasColumn('orders', 'confirmation_status')) {
                $table->string('confirmation_status')->default('pending');
            }

            if (!Schema::hasColumn('orders', 'whatsapp_sent_at')) {
                $table->timestamp('whatsapp_sent_at')->nullable();
            }
            if (!Schema::hasColumn('orders', 'whatsapp_message_id')) {
                $table->string('whatsapp_message_id')->nullable();
            }
            if (!Schema::hasColumn('orders', 'whatsapp_message_sent')) {
                $table->text('whatsapp_message_sent')->nullable();
            }

            if (!Schema::hasColumn('orders', 'ai_generated_message')) {
                $table->boolean('ai_generated_message')->default(false);
            }
            if (!Schema::hasColumn('orders', 'ai_message_generated')) {
                $table->text('ai_message_generated')->nullable();
            }
            if (!Schema::hasColumn('orders', 'ai_cost')) {
                $table->decimal('ai_cost', 8, 4)->default(0);
            }

            if (!Schema::hasColumn('orders', 'n8n_execution_id')) {
                $table->string('n8n_execution_id')->nullable();
            }
            if (!Schema::hasColumn('orders', 'n8n_workflow_steps')) {
                $table->json('n8n_workflow_steps')->nullable();
            }

            if (!Schema::hasColumn('orders', 'customer_reply_raw')) {
                $table->text('customer_reply_raw')->nullable();
            }
            if (!Schema::hasColumn('orders', 'customer_reply_parsed')) {
                $table->json('customer_reply_parsed')->nullable();
            }
            if (!Schema::hasColumn('orders', 'last_intent')) {
                $table->string('last_intent')->nullable();
            }
            if (!Schema::hasColumn('orders', 'last_confidence')) {
                $table->decimal('last_confidence', 3, 2)->nullable();
            }

            if (!Schema::hasColumn('orders', 'whatsapp_interactions')) {
                $table->json('whatsapp_interactions')->nullable();
            }

            if (!Schema::hasColumn('orders', 'whatsapp_confirmed_at')) {
                $table->timestamp('whatsapp_confirmed_at')->nullable();
            }
            if (!Schema::hasColumn('orders', 'whatsapp_cancelled_at')) {
                $table->timestamp('whatsapp_cancelled_at')->nullable();
            }

            if (!Schema::hasColumn('orders', 'confirmation_retry_count')) {
                $table->unsignedInteger('confirmation_retry_count')->default(0);
            }
            if (!Schema::hasColumn('orders', 'confirmation_last_retry_at')) {
                $table->timestamp('confirmation_last_retry_at')->nullable();
            }
        });
    }

    public function down(): void
    {
        Schema::table('orders', function (Blueprint $table): void {
            $table->dropColumn([
                'confirmation_method',
                'confirmation_tier',
                'confirmation_status',
                'whatsapp_sent_at',
                'whatsapp_message_id',
                'whatsapp_message_sent',
                'ai_generated_message',
                'ai_message_generated',
                'ai_cost',
                'n8n_execution_id',
                'n8n_workflow_steps',
                'customer_reply_raw',
                'customer_reply_parsed',
                'last_intent',
                'last_confidence',
                'whatsapp_interactions',
                'whatsapp_confirmed_at',
                'whatsapp_cancelled_at',
                'confirmation_retry_count',
                'confirmation_last_retry_at',
            ]);
        });
    }
};
