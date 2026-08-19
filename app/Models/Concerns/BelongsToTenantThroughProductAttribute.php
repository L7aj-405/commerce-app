<?php

declare(strict_types=1);

namespace App\Models\Concerns;

use App\Models\Scopes\TenantThroughProductAttributeScope;
use App\Support\TenantContext;
use Closure;

trait BelongsToTenantThroughProductAttribute
{
    public static function bootBelongsToTenantThroughProductAttribute(): void
    {
        static::addGlobalScope(new TenantThroughProductAttributeScope());
    }

    public static function withoutTenancy(Closure $callback): mixed
    {
        return app(TenantContext::class)->runWithout($callback);
    }
}
