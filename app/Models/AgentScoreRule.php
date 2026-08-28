<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;

/**
 * Configurable points-per-event-type rule — points/bonus system FOUNDATION
 * only. See the creating migration for the full rationale and seeded
 * defaults. `organization_id` null = global default, used by every store
 * until a per-organization override exists (no override UI is built yet).
 */
class AgentScoreRule extends Model
{
    use HasUlids;

    protected $fillable = [
        'organization_id', 'event_type', 'points_delta', 'label', 'is_active',
    ];

    protected function casts(): array
    {
        return [
            'points_delta' => 'integer',
            'is_active' => 'boolean',
        ];
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }

    /**
     * The effective rule set for an organization: its own overrides where
     * they exist, falling back to the global defaults otherwise.
     *
     * @return array<string, int> event_type => points_delta
     */
    public static function effectiveRatesFor(?string $organizationId): array
    {
        $global = self::query()->active()->whereNull('organization_id')->pluck('points_delta', 'event_type')->all();

        if ($organizationId === null) {
            return $global;
        }

        $overrides = self::query()->active()->where('organization_id', $organizationId)->pluck('points_delta', 'event_type')->all();

        return array_merge($global, $overrides);
    }
}
