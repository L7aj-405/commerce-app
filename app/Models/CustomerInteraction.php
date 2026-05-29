<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CustomerInteraction extends Model
{
    use HasUlids;

    protected $fillable = [
        'order_id',
        'customer_phone',
        'interaction_type',
        'raw_message',
        'ai_analysis',
        'detected_intent',
        'confidence',
        'action_taken',
    ];

    protected function casts(): array
    {
        return [
            'ai_analysis' => 'array',
            'confidence'  => 'decimal:2',
        ];
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }
}
