<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Append-only tracking history for one shipment. */
class ShipmentEvent extends Model
{
    use BelongsToTenant, HasUlids;

    public $timestamps = false;

    protected $fillable = [
        'store_id', 'shipment_id', 'provider_code',
        'provider_status', 'normalized_status', 'message', 'raw_payload', 'occurred_at',
    ];

    protected function casts(): array
    {
        return [
            'raw_payload' => 'array',
            'occurred_at' => 'datetime',
            'created_at' => 'datetime',
        ];
    }

    public function shipment(): BelongsTo
    {
        return $this->belongsTo(Shipment::class);
    }
}
