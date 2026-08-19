<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class PosSession extends Model
{
    use BelongsToTenant, HasUlids;

    protected $keyType = 'string';
    public $incrementing = false;

    protected $fillable = [
        'store_id',
        'cashier_id',
        'status',
        'opening_balance',
        'closing_balance',
        'opened_at',
        'closed_at',
        'total_sales',
        'total_refunds',
        'total_transactions',
        'notes',
        'closing_notes',
    ];

    protected $casts = [
        'opening_balance'    => 'decimal:2',
        'closing_balance'    => 'decimal:2',
        'total_sales'        => 'decimal:2',
        'total_refunds'      => 'decimal:2',
        'total_transactions' => 'integer',
        'opened_at'          => 'datetime',
        'closed_at'          => 'datetime',
    ];

    public function store(): BelongsTo
    {
        return $this->belongsTo(Store::class);
    }

    public function cashier(): BelongsTo
    {
        return $this->belongsTo(User::class, 'cashier_id');
    }

    public function orders(): HasMany
    {
        return $this->hasMany(PosOrder::class);
    }

    public function isOpen(): bool
    {
        return $this->status === 'open';
    }
}
