<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** One row per (sync batch, platform connection) — a sync operates on a whole connection's catalog, not a single product. */
class ProductSyncResult extends Model
{
    use BelongsToTenant, HasUlids;

    public const STATUS_QUEUED = 'queued';
    public const STATUS_RUNNING = 'running';
    public const STATUS_SUCCEEDED = 'succeeded';
    public const STATUS_FAILED = 'failed';
    public const STATUS_SKIPPED = 'skipped';

    protected $keyType = 'string';
    public $incrementing = false;

    protected $fillable = [
        'batch_id',
        'store_id',
        'platform_connection_id',
        'platform',
        'status',
        'created_count',
        'updated_count',
        'failed_item_count',
        'message',
    ];

    public function batch(): BelongsTo
    {
        return $this->belongsTo(ProductSyncBatch::class, 'batch_id');
    }

    public function connection(): BelongsTo
    {
        return $this->belongsTo(PlatformConnection::class, 'platform_connection_id');
    }
}
