<?php

declare(strict_types=1);

namespace App\Models\Concerns;

use App\Models\Scopes\TenantScope;
use App\Support\TenantContext;
use Closure;
use Illuminate\Database\Eloquent\Model;

/**
 * Applies the store-level tenant scope to a model and auto-fills `store_id` on
 * create from the current tenant. Models using this MUST have a `store_id` column.
 */
trait BelongsToTenant
{
    public static function bootBelongsToTenant(): void
    {
        static::addGlobalScope(new TenantScope());

        static::creating(function (Model $model): void {
            $context = app(TenantContext::class);

            $storeId = $context->queryStoreId();

            if ($storeId !== null && empty($model->getAttribute('store_id'))) {
                $model->setAttribute('store_id', $storeId);
            }
        });
    }

    /** Run a query/closure with the tenant scope disabled (jobs, cross-store admin). */
    public static function withoutTenancy(Closure $callback): mixed
    {
        return app(TenantContext::class)->runWithout($callback);
    }
}
