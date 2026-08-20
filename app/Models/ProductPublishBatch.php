<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ProductPublishBatch extends Model
{
    use BelongsToTenant, HasUlids;

    public const STATUS_PENDING = 'pending';
    public const STATUS_RUNNING = 'running';
    public const STATUS_COMPLETED = 'completed';
    public const STATUS_FAILED = 'failed';
    public const STATUS_PARTIAL = 'partial';

    protected $keyType = 'string';
    public $incrementing = false;

    protected $fillable = [
        'store_id',
        'organization_id',
        'user_id',
        'status',
        'total_count',
        'succeeded_count',
        'failed_count',
        'skipped_count',
        'payload',
    ];

    protected function casts(): array
    {
        return ['payload' => 'array'];
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
        return $this->hasMany(ProductPublishResult::class, 'batch_id');
    }

    /** Recompute counts/status from the batch's own result rows. */
    public function refreshCounts(): void
    {
        $counts = $this->results()->selectRaw('status, count(*) as total')->groupBy('status')->pluck('total', 'status');

        $succeeded = (int) ($counts['succeeded'] ?? 0);
        $failed = (int) ($counts['failed'] ?? 0);
        $skipped = (int) ($counts['skipped'] ?? 0);
        $pending = (int) ($counts['queued'] ?? 0) + (int) ($counts['running'] ?? 0);

        $status = match (true) {
            $pending > 0 => self::STATUS_RUNNING,
            $failed > 0 && $succeeded > 0 => self::STATUS_PARTIAL,
            $failed > 0 && $succeeded === 0 && $skipped === 0 => self::STATUS_FAILED,
            $failed > 0 => self::STATUS_PARTIAL,
            default => self::STATUS_COMPLETED,
        };

        $this->update([
            'succeeded_count' => $succeeded,
            'failed_count' => $failed,
            'skipped_count' => $skipped,
            'status' => $status,
        ]);
    }
}
