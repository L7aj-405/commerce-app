<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class BonDeLivraison extends Model
{
    use BelongsToTenant, HasUlids;

    protected $table = 'bon_de_livraisons';

    protected $keyType = 'string';
    public $incrementing = false;

    protected $fillable = [
        'store_id',
        'pos_order_id',
        'facture_id',
        'bon_number',
        'status',
        'issued_date',
        'expected_delivery_date',
        'shipped_at',
        'delivered_at',
        'customer_name',
        'customer_phone',
        'delivery_address',
        'total_items',
        'items_delivered',
        'tracking_number',
        'shipping_method',
        'pdf_path',
        'notes',
    ];

    protected $casts = [
        'issued_date'            => 'date',
        'expected_delivery_date' => 'date',
        'shipped_at'             => 'datetime',
        'delivered_at'           => 'datetime',
        'total_items'            => 'integer',
        'items_delivered'        => 'integer',
    ];

    public function store(): BelongsTo
    {
        return $this->belongsTo(Store::class);
    }

    public function posOrder(): BelongsTo
    {
        return $this->belongsTo(PosOrder::class, 'pos_order_id');
    }

    public function facture(): BelongsTo
    {
        return $this->belongsTo(Facture::class);
    }
}
