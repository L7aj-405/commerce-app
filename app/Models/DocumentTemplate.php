<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\BelongsToOrganization;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A per-organization (optionally per-store) customization of ONE internal
 * fulfilment document type. The system defaults live in
 * config/documents.php; a row here only carries a partial `settings`
 * override that DocumentTemplateResolver deep-merges over them.
 *
 * Provider PDFs (Ozon BL, Sendit labels) are never represented here.
 */
class DocumentTemplate extends Model
{
    use BelongsToOrganization, HasUlids;

    protected $fillable = [
        'organization_id', 'store_id', 'document_type', 'name',
        'is_active', 'settings', 'body', 'created_by', 'updated_by',
    ];

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
            'settings' => 'array',
        ];
    }

    public function store(): BelongsTo
    {
        return $this->belongsTo(Store::class);
    }

    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }
}
