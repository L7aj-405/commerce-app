<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class DeliveryProvider extends Model
{
    use HasUlids;

    public const INTERNAL = 'internal';
    public const OZON = 'ozon';
    public const SENDIT = 'sendit';

    protected $fillable = ['code', 'name', 'is_active'];

    protected function casts(): array
    {
        return ['is_active' => 'boolean'];
    }

    /**
     * Every organization's finance setup for this provider — multiple rows
     * across different tenants (this catalogue itself is global/org-agnostic).
     * Callers almost always further filter by organization_id.
     */
    public function financeSettings(): HasMany
    {
        return $this->hasMany(DeliveryProviderFinanceSetting::class);
    }
}
