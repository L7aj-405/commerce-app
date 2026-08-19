<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AgencyClientRelationship extends Model
{
    use HasUlids;

    public const STATUS_ACTIVE = 'active';
    public const STATUS_SUSPENDED = 'suspended';

    protected $table = 'agency_client_organizations';
    protected $keyType = 'string';
    public $incrementing = false;

    protected $fillable = [
        'agency_organization_id',
        'client_organization_id',
        'status',
        'settings',
    ];

    protected function casts(): array
    {
        return ['settings' => 'array'];
    }

    public function agency(): BelongsTo
    {
        return $this->belongsTo(Organization::class, 'agency_organization_id');
    }

    public function client(): BelongsTo
    {
        return $this->belongsTo(Organization::class, 'client_organization_id');
    }

    public function isActive(): bool
    {
        return $this->status === self::STATUS_ACTIVE;
    }
}
