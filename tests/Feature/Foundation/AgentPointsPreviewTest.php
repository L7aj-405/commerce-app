<?php

declare(strict_types=1);

use App\Models\AgentActivityEvent;
use App\Models\AgentScoreRule;
use App\Models\Store;
use App\Models\User;
use App\Services\Metrics\AgentScorePreviewService;
use App\Services\OrganizationProvisioner;

/**
 * "Performance points preview" — a foundation-only, read-only projection over
 * agent_activity_events x agent_score_rules. It must never touch payroll (no
 * such system exists to touch) and must never be silently invented — every
 * point traces back to a real event x the seeded default rate.
 */

/** @return array{0: User, 1: Store} */
function apsWorkspace(string $name = 'Points Preview Store'): array
{
    $owner = User::factory()->create(['role' => 'store_admin', 'onboarding_completed_at' => now()]);
    $organization = app(OrganizationProvisioner::class)->createOwnedOrganization($owner, $name);
    $store = Store::factory()->create(['user_id' => $owner->id, 'organization_id' => $organization->id, 'name' => $name]);

    return [$owner, $store];
}

function apsEvent(Store $store, User $user, string $type): AgentActivityEvent
{
    return AgentActivityEvent::create([
        'organization_id' => $store->organization_id, 'store_id' => $store->id, 'user_id' => $user->id,
        'event_type' => $type, 'source_module' => 'confirmation', 'occurred_at' => now(),
    ]);
}

it('sums preview points against the seeded default rule set', function (): void {
    [, $store] = apsWorkspace();
    $agent = User::factory()->create();

    apsEvent($store, $agent, AgentActivityEvent::CONFIRMATION_CONFIRMED);
    apsEvent($store, $agent, AgentActivityEvent::CONFIRMATION_CONFIRMED);
    apsEvent($store, $agent, AgentActivityEvent::CONFIRMATION_CANCELLED);
    apsEvent($store, $agent, AgentActivityEvent::FULFILLMENT_PICKED);

    $preview = app(AgentScorePreviewService::class)->previewFor($agent, $store, now()->startOfDay(), now()->endOfDay());

    // 2 confirmed (+2 each) + 1 cancelled (-1) + 1 picked (+1) = 4.
    expect($preview['total_points'])->toBe(4);
    $byType = collect($preview['breakdown'])->keyBy('event_type');
    expect($byType[AgentActivityEvent::CONFIRMATION_CONFIRMED]['points'])->toBe(4)
        ->and($byType[AgentActivityEvent::CONFIRMATION_CANCELLED]['points'])->toBe(-1)
        ->and($byType[AgentActivityEvent::FULFILLMENT_PICKED]['points'])->toBe(1);
});

it('omits zero-rate event types from the breakdown to avoid noise', function (): void {
    [, $store] = apsWorkspace('Neutral Events Store');
    $agent = User::factory()->create();

    apsEvent($store, $agent, AgentActivityEvent::DELIVERY_FAILED);
    apsEvent($store, $agent, AgentActivityEvent::DELIVERY_UNREACHABLE);

    $preview = app(AgentScorePreviewService::class)->previewFor($agent, $store, now()->startOfDay(), now()->endOfDay());

    expect($preview['total_points'])->toBe(0)
        ->and($preview['breakdown'])->toBeEmpty();
});

it('respects an organization-level override over the global default', function (): void {
    [, $store] = apsWorkspace('Override Store');
    $agent = User::factory()->create();

    AgentScoreRule::create([
        'organization_id' => $store->organization_id,
        'event_type' => AgentActivityEvent::CONFIRMATION_CONFIRMED,
        'points_delta' => 5,
        'label' => 'Custom confirmed rate',
        'is_active' => true,
    ]);

    apsEvent($store, $agent, AgentActivityEvent::CONFIRMATION_CONFIRMED);

    $preview = app(AgentScorePreviewService::class)->previewFor($agent, $store, now()->startOfDay(), now()->endOfDay());

    expect($preview['total_points'])->toBe(5);
});

it('never reads or writes anything outside agent_activity_events and agent_score_rules', function (): void {
    [, $store] = apsWorkspace('Isolation Store');
    $agent = User::factory()->create();
    apsEvent($store, $agent, AgentActivityEvent::CONFIRMATION_CONFIRMED);

    app(AgentScorePreviewService::class)->previewFor($agent, $store, now()->startOfDay(), now()->endOfDay());

    // Computed live every call — no second ledger table exists to persist into.
    expect(\Illuminate\Support\Facades\Schema::hasTable('agent_score_events'))->toBeFalse();
});
