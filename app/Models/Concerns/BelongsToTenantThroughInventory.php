<?php

declare(strict_types=1);

namespace App\Models\Concerns;

use App\Models\Scopes\TenantInventoryScope;
use App\Support\TenantContext;
use Closure;

/** Tenant isolation for models carrying product_id + warehouse_id. */
trait BelongsToTenantThroughInventory
{
    public static function bootBelongsToTenantThroughInventory(): void
    {
        static::addGlobalScope(new TenantInventoryScope());
    }

    public static function withoutTenancy(Closure $callback): mixed
    {
        return app(TenantContext::class)->runWithout($callback);
    }
}
