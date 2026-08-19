<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class OrganizationServiceAssignment extends Model
{
    use HasUlids;

    /**
     * Remote/operational services that can be delegated independently.
     * Picking, packing and dispatch are deliberately absent: those are derived
     * from the physical warehouse operator in the fulfillment layer.
     */
    public const SERVICE_CONFIRMATION = 'confirmation';
    public const SERVICE_CUSTOMER_SUPPORT = 'customer_support';
    public const SERVICE_DELIVERY = 'delivery';

    public const SERVICE_CODES = [
        self::SERVICE_CONFIRMATION,
        self::SERVICE_CUSTOMER_SUPPORT,
        self::SERVICE_DELIVERY,
    ];

    protected $keyType = 'string';
    public $incrementing = false;

    protected $fillable = [
        'client_organization_id',
        'service_code',
        'operator_organization_id',
        'is_active',
        'settings',
    ];

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
            'settings' => 'array',
        ];
    }

    public function client(): BelongsTo
    {
        return $this->belongsTo(Organization::class, 'client_organization_id');
    }

    public function operator(): BelongsTo
    {
        return $this->belongsTo(Organization::class, 'operator_organization_id');
    }
}
