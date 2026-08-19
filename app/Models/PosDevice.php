<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PosDevice extends Model
{
    use BelongsToTenant, HasUlids;

    protected $keyType = 'string';
    public $incrementing = false;

    protected $fillable = [
        'store_id',
        'device_name',
        'device_id',
        'status',
        'receipt_printer_id',
        'thermal_paper_width',
        'auto_print_receipts',
        'current_session_id',
    ];

    protected $casts = [
        'auto_print_receipts' => 'boolean',
    ];

    public function store(): BelongsTo
    {
        return $this->belongsTo(Store::class);
    }

    public function currentSession(): BelongsTo
    {
        return $this->belongsTo(PosSession::class, 'current_session_id');
    }
}
