<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** Mirrors ProductSyncBatch — one row per queued "Sync orders now"/"Full order resync" action, summed from its own OrderSyncResult rows. */
class OrderSyncBatch extends Model
{
    use BelongsToTenant, HasUlids;

    public const STATUS_QUEUED = 'queued';
    public const STATUS_RUNNING = 'running';
    public const STATUS_COMPLETED = 'completed';
    public const STATUS_FAILED = 'failed';

    protected $keyType = 'string';
    public $incrementing = false;

    protected $fillable = [
        'store_id',
        'organization_id',
        'user_id',
        'status',
        'total_count',
        'imported_count',
        'updated_count',
        'skipped_count',
        'failed_count',
        'last_error',
        'payload',
        'started_at',
        'completed_at',
    ];

    protected function casts(): array
    {
        return [
            'payload' => 'array',
            'started_at' => 'datetime',
            'completed_at' => 'datetime',
        ];
    }

    public function store(): BelongsTo
    {
        return $this->belongsTo(Store::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function results(): HasMany
    {
        return $this->hasMany(OrderSyncResult::class, 'batch_id');
    }

    /** Recompute the batch's own counts/status from its result rows — same shape as ProductSyncBatch::refreshCounts(). */
    public function refreshCounts(): void
    {
        $results = $this->results()->get();

        $pending = $results->whereIn('status', [OrderSyncResult::STATUS_QUEUED, OrderSyncResult::STATUS_RUNNING])->count();
        $failed = $results->where('status', OrderSyncResult::STATUS_FAILED)->count();

        $this->update([
            'imported_count' => (int) $results->sum('imported_count'),
            'updated_count' => (int) $results->sum('updated_count'),
            'skipped_count' => (int) $results->sum('skipped_count'),
            'failed_count' => (int) $results->sum('failed_count'),
            'last_error' => $results->firstWhere('status', OrderSyncResult::STATUS_FAILED)?->last_error,
            'status' => match (true) {
                $pending > 0 => self::STATUS_RUNNING,
                $failed > 0 && $failed === $results->count() => self::STATUS_FAILED,
                default => self::STATUS_COMPLETED,
            },
            'completed_at' => $pending > 0 ? null : now(),
        ]);
    }
}
