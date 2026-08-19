<?php

declare(strict_types=1);

namespace App\Models\Concerns;

use App\Models\Scopes\TenantThroughProductScope;
use App\Support\TenantContext;
use Closure;

/**
 * Applies tenant isolation to models whose ownership is inherited from a
 * product_id column instead of a local store_id column.
 */
trait BelongsToTenantThroughProduct
{
    public static function bootBelongsToTenantThroughProduct(): void
    {
        static::addGlobalScope(new TenantThroughProductScope());
    }

    public static function withoutTenancy(Closure $callback): mixed
    {
        return app(TenantContext::class)->runWithout($callback);
    }
}
