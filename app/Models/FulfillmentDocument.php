<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\FulfillmentDocumentStatus;
use App\Enums\FulfillmentDocumentType;
use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;

/**
 * One piece of fulfilment paperwork — a fetched Ozon BL/label PDF, or a
 * SaaS-generated fallback label — attached to a Shipment, DeliveryNote or
 * Order (see `documentable_*`).
 *
 * Purely operational paperwork: nothing here is ever read by the ledger and
 * generating/downloading a document NEVER writes a finance_transaction.
 * `path`/`disk` are hidden — downloads always go through the authorized
 * controller route, never a raw storage path.
 */
class FulfillmentDocument extends Model
{
    use BelongsToTenant, HasUlids;

    protected $fillable = [
        'store_id', 'organization_id',
        'documentable_type', 'documentable_id',
        'document_type', 'status', 'provider_code',
        'disk', 'path', 'source_url', 'mime_type', 'original_name', 'size_bytes',
        'generated_by', 'generated_at', 'metadata',
    ];

    protected $hidden = ['path', 'disk'];

    protected $appends = ['label', 'download_url', 'is_downloadable'];

    protected function casts(): array
    {
        return [
            'document_type' => FulfillmentDocumentType::class,
            'status' => FulfillmentDocumentStatus::class,
            'size_bytes' => 'integer',
            'generated_at' => 'datetime',
            'metadata' => 'array',
        ];
    }

    public function documentable(): MorphTo
    {
        return $this->morphTo();
    }

    public function generatedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'generated_by');
    }

    public function getLabelAttribute(): string
    {
        return $this->document_type?->label() ?? 'Document';
    }

    /** Null unless there is a real file on disk — the client never sees a raw path. */
    public function getDownloadUrlAttribute(): ?string
    {
        return $this->isDownloadableValue()
            ? "/dashboard/fulfillment-documents/{$this->id}/download"
            : null;
    }

    public function getIsDownloadableAttribute(): bool
    {
        return $this->isDownloadableValue();
    }

    private function isDownloadableValue(): bool
    {
        return $this->path !== null && ($this->status?->isDownloadable() ?? false);
    }
}
