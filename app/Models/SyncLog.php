<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SyncLog extends Model
{
    use HasUlids;

    protected $keyType = 'string';
    public $incrementing = false;

    protected $fillable = [
        'store_id',
        'platform_connection_id',
        'platform',
        'direction', // ← ADD THIS
        'action', // ← ADD THIS
        'entity_id', // ← ADD THIS
        'external_id', // ← ADD THIS
        'status', // ← ADD THIS
        'product_count',
        'order_count',
        'error',
        'error_message', // ← ADD THIS
        'completed_at', // ← ADD THIS
        'platform_connection_id',
        'type',
        
       
        'started_at',
        
        'records_processed',
        'records_failed',
        
        'summary',
    ];
   

    

    protected function casts(): array
    {
        return [
            'started_at'   => 'datetime',
            'completed_at' => 'datetime',
            'summary'      => 'array',
            
        ];
    }

    // Relationships
    public function store(): BelongsTo
    {
        return $this->belongsTo(Store::class);
    }

    public function connection(): BelongsTo
    {
        return $this->belongsTo(PlatformConnection::class, 'platform_connection_id');
    }

    
}
