<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

/** A carrier handover batch (provider-side BL), distinct from the internal MAN- manifest system. */
class DeliveryNote extends Model
{
    use BelongsToTenant, HasUlids;

    public const STATUS_DRAFT = 'draft';
    public const STATUS_SAVED = 'saved';

    protected $fillable = [
        'store_id', 'delivery_connection_id', 'provider_code',
        'provider_ref', 'status', 'pdf_url', 'labels_pdf_url', 'raw_payload', 'saved_at',
    ];

    protected function casts(): array
    {
        return [
            'raw_payload' => 'array',
            'saved_at' => 'datetime',
        ];
    }

    public function connection(): BelongsTo
    {
        return $this->belongsTo(DeliveryConnection::class, 'delivery_connection_id');
    }

    public function shipments(): BelongsToMany
    {
        return $this->belongsToMany(Shipment::class, 'delivery_note_shipments');
    }

    public function fulfillmentDocuments(): \Illuminate\Database\Eloquent\Relations\MorphMany
    {
        return $this->morphMany(FulfillmentDocument::class, 'documentable');
    }
}
