<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\FinanceDocumentType;
use App\Models\Concerns\BelongsToOrganization;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * A supporting document/justificatif attached to a Finance record (currently
 * FinanceExpense — see `documentable_*`, kept generic for future reuse by
 * FinanceCodSettlement/FinanceCourierDeposit/vendor documents).
 *
 * Purely evidence/traceability: nothing here is ever read by the ledger, and
 * nothing here ever writes a finance_transaction. Soft-deleted (never hard
 * deleted, and the physical file is never removed by the app) so cancelled
 * expenses and their history stay fully auditable — see
 * FinanceDocumentService::delete().
 */
class FinanceDocument extends Model
{
    use BelongsToOrganization, HasUlids, SoftDeletes;

    protected $keyType = 'string';
    public $incrementing = false;

    protected $fillable = [
        'organization_id',
        'store_id',
        'documentable_type',
        'documentable_id',
        'original_name',
        'stored_name',
        'disk',
        'path',
        'mime_type',
        'extension',
        'size_bytes',
        'document_type',
        'description',
        'uploaded_by',
        'deleted_by',
    ];

    protected $casts = [
        'size_bytes' => 'integer',
        'document_type' => FinanceDocumentType::class,
    ];

    /**
     * Never expose the on-disk path/disk to the client — downloads/previews
     * always go through the authorized controller route below instead.
     */
    protected $hidden = [
        'path',
        'disk',
        'stored_name',
    ];

    protected $appends = [
        'human_size',
        'download_url',
        'preview_url',
        'is_official_document',
        'is_internal_document',
    ];

    public function getHumanSizeAttribute(): string
    {
        return $this->humanSize();
    }

    /** External legal proof — see FinanceDocumentType::officialTypes(). */
    public function getIsOfficialDocumentAttribute(): bool
    {
        return $this->document_type?->isOfficial() ?? false;
    }

    /** Internal justification only — never a substitute for official proof, see FinanceDocumentType::internalTypes(). */
    public function getIsInternalDocumentAttribute(): bool
    {
        return $this->document_type?->isInternal() ?? false;
    }

    public function getDownloadUrlAttribute(): string
    {
        return route('dashboard.finance.documents.download', $this->id);
    }

    public function getPreviewUrlAttribute(): ?string
    {
        return $this->isPreviewable() ? route('dashboard.finance.documents.preview', $this->id) : null;
    }

    public function documentable(): MorphTo
    {
        return $this->morphTo();
    }

    public function uploadedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'uploaded_by');
    }

    public function deletedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'deleted_by');
    }

    public function isPreviewable(): bool
    {
        return $this->mime_type === 'application/pdf' || str_starts_with((string) $this->mime_type, 'image/');
    }

    public function humanSize(): string
    {
        $bytes = (int) $this->size_bytes;

        if ($bytes < 1024) {
            return "{$bytes} B";
        }

        $kb = $bytes / 1024;
        if ($kb < 1024) {
            return round($kb, 1).' KB';
        }

        return round($kb / 1024, 1).' MB';
    }
}
