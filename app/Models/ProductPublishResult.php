<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\BelongsToTenantThroughProduct;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ProductPublishResult extends Model
{
    use BelongsToTenantThroughProduct, HasUlids;

    public const STATUS_QUEUED = 'queued';
    public const STATUS_RUNNING = 'running';
    public const STATUS_SUCCEEDED = 'succeeded';
    public const STATUS_FAILED = 'failed';
    public const STATUS_SKIPPED = 'skipped';

    protected $keyType = 'string';
    public $incrementing = false;

    protected $fillable = [
        'batch_id',
        'product_id',
        'product_variant_id',
        'platform_connection_id',
        'platform',
        'status',
        'message',
        'external_product_id',
        'external_variant_id',
        'error_code',
    ];

    public function batch(): BelongsTo
    {
        return $this->belongsTo(ProductPublishBatch::class, 'batch_id');
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function variant(): BelongsTo
    {
        return $this->belongsTo(ProductVariant::class, 'product_variant_id');
    }

    public function connection(): BelongsTo
    {
        return $this->belongsTo(PlatformConnection::class, 'platform_connection_id');
    }
}
