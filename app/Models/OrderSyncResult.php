<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** One row per (order sync batch, platform connection) — mirrors ProductSyncResult. */
class OrderSyncResult extends Model
{
    use BelongsToTenant, HasUlids;

    public const STATUS_QUEUED = 'queued';
    public const STATUS_RUNNING = 'running';
    public const STATUS_COMPLETED = 'completed';
    public const STATUS_FAILED = 'failed';

    protected $keyType = 'string';
    public $incrementing = false;

    protected $fillable = [
        'batch_id',
        'store_id',
        'platform_connection_id',
        'platform',
        'status',
        'full_resync',
        'imported_count',
        'updated_count',
        'skipped_count',
        'failed_count',
        'last_error',
        'started_at',
        'completed_at',
    ];

    protected function casts(): array
    {
        return [
            'full_resync' => 'boolean',
            'started_at' => 'datetime',
            'completed_at' => 'datetime',
        ];
    }

    public function batch(): BelongsTo
    {
        return $this->belongsTo(OrderSyncBatch::class, 'batch_id');
    }

    public function connection(): BelongsTo
    {
        return $this->belongsTo(PlatformConnection::class, 'platform_connection_id');
    }
}
