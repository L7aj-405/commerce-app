<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('customer_interactions', function (Blueprint $table): void {
            $table->char('id', 26)->primary();
            $table->char('order_id', 26);
            $table->foreign('order_id')->references('id')->on('orders')->cascadeOnDelete();

            $table->string('customer_phone');
            $table->string('interaction_type');
            $table->text('raw_message');
            $table->json('ai_analysis')->nullable();
            $table->string('detected_intent')->nullable();
            $table->decimal('confidence', 3, 2)->nullable();
            $table->string('action_taken')->nullable();

            $table->timestamps();

            $table->index('customer_phone');
            $table->index('interaction_type');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('customer_interactions');
    }
};
