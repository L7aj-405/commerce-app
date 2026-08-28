<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;

class DeliveryNoteShipment extends Model
{
    use HasUlids;

    protected $fillable = ['delivery_note_id', 'shipment_id'];
}
