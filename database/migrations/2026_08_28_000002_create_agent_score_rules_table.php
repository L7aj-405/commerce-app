<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

/**
 * Configurable points-per-event-type rules — the foundation for a future
 * points/bonus system. `organization_id` null means "global default rule",
 * applied to every store that hasn't overridden it (no override UI exists
 * yet; this phase only ships the global defaults below).
 *
 * These are explicitly a PREVIEW input (see AgentScorePreviewService) — never
 * read by payroll/invoicing, because no such system exists in this codebase.
 * Deliberately conservative: no rule rewards raw unit volume, and
 * failure/unreachable outcomes default to neutral (0), not punitive.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('agent_score_rules', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->ulid('organization_id')->nullable();
            $table->string('event_type');
            $table->integer('points_delta');
            $table->string('label');
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->unique(['organization_id', 'event_type']);
        });

        $now = now();

        $defaults = [
            ['event_type' => 'confirmation.confirmed',    'points_delta' => 2,  'label' => 'Order confirmed'],
            ['event_type' => 'confirmation.cancelled',    'points_delta' => -1, 'label' => 'Order cancelled at confirmation'],
            ['event_type' => 'confirmation.unreachable',  'points_delta' => 0,  'label' => 'Customer unreachable (handled)'],
            ['event_type' => 'fulfillment.picked',        'points_delta' => 1,  'label' => 'Order picked'],
            ['event_type' => 'fulfillment.packed',        'points_delta' => 1,  'label' => 'Order packed'],
            ['event_type' => 'delivery.delivered',        'points_delta' => 2,  'label' => 'Order delivered'],
            ['event_type' => 'delivery.failed',           'points_delta' => 0,  'label' => 'Delivery failed'],
            ['event_type' => 'delivery.unreachable',      'points_delta' => 0,  'label' => 'Customer unreachable on delivery'],
            ['event_type' => 'return.inspected',          'points_delta' => 1,  'label' => 'Return inspected'],
            ['event_type' => 'stock.transfer.received',   'points_delta' => 1,  'label' => 'Stock transfer received'],
        ];

        foreach ($defaults as $rule) {
            DB::table('agent_score_rules')->insert([
                'id' => (string) Str::ulid(),
                'organization_id' => null,
                'event_type' => $rule['event_type'],
                'points_delta' => $rule['points_delta'],
                'label' => $rule['label'],
                'is_active' => true,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('agent_score_rules');
    }
};
