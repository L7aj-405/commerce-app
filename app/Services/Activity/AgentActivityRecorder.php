<?php

declare(strict_types=1);

namespace App\Services\Activity;

use App\Models\AgentActivityEvent;
use App\Models\Store;
use App\Models\User;

/**
 * The single write path for the agent/operational activity ledger
 * (agent_activity_events). Every call site records AFTER the underlying
 * workflow action has already succeeded — this never participates in, and
 * can never fail, an existing workflow transaction's business outcome.
 *
 * Deliberately NOT called when there is no human actor (e.g. a customer's
 * own WhatsApp confirmation reply, which calls OrderWorkflowService::
 * transition() with $actor = null) — see call sites, which simply skip
 * calling record() in that case. That single rule is what keeps this ledger
 * "agent activity" and not "every status change."
 */
class AgentActivityRecorder
{
    /**
     * @param  array{subject?: \Illuminate\Database\Eloquent\Model|null, order_id?: ?string, order_item_id?: ?string, metadata?: array<string, mixed>, occurred_at?: \DateTimeInterface}  $attributes
     */
    public function record(User $actor, Store $store, string $eventType, string $sourceModule, array $attributes = []): AgentActivityEvent
    {
        $subject = $attributes['subject'] ?? null;

        return AgentActivityEvent::create([
            'organization_id' => $store->organization_id,
            'store_id' => $store->id,
            'user_id' => $actor->id,
            'role_context' => $actor->accessProfileForStore($store)['roleSlug'] ?? null,
            'event_type' => $eventType,
            'subject_type' => $subject?->getMorphClass(),
            'subject_id' => $subject?->getKey(),
            'order_id' => $attributes['order_id'] ?? null,
            'order_item_id' => $attributes['order_item_id'] ?? null,
            'source_module' => $sourceModule,
            'metadata' => $attributes['metadata'] ?? null,
            'occurred_at' => $attributes['occurred_at'] ?? now(),
        ]);
    }
}
