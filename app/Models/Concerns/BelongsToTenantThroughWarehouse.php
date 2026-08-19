<?php

declare(strict_types=1);

namespace App\Models\Concerns;

use App\Models\Scopes\TenantWarehouseScope;
use App\Support\TenantContext;
use Closure;

trait BelongsToTenantThroughWarehouse
{
    public static function bootBelongsToTenantThroughWarehouse(): void
    {
        static::addGlobalScope(new TenantWarehouseScope());
    }

    public static function withoutTenancy(Closure $callback): mixed
    {
        return app(TenantContext::class)->runWithout($callback);
    }
}
