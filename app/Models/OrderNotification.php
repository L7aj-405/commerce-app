<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Per-user "new order" notification row — one per (user, order, type), see the migration's own doc comment for why. */
class OrderNotification extends Model
{
    use BelongsToTenant, HasUlids;

    public const TYPE_NEW_ORDER = 'new_order';

    protected $keyType = 'string';
    public $incrementing = false;

    protected $fillable = [
        'store_id',
        'organization_id',
        'user_id',
        'order_id',
        'type',
        'source_platform',
        'title',
        'message',
        'seen_at',
    ];

    protected function casts(): array
    {
        return ['seen_at' => 'datetime'];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    public function scopeUnseen(Builder $query): Builder
    {
        return $query->whereNull('seen_at');
    }

    public function scopeForUser(Builder $query, string $userId): Builder
    {
        return $query->where('user_id', $userId);
    }
}
