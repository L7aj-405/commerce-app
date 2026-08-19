<?php

declare(strict_types=1);

namespace App\Models\Concerns;

use App\Models\Scopes\OrganizationTenantScope;
use App\Support\TenantContext;
use Closure;
use Illuminate\Database\Eloquent\Model;

trait BelongsToOrganization
{
    public static function bootBelongsToOrganization(): void
    {
        static::addGlobalScope(new OrganizationTenantScope());
        static::creating(function (Model $model): void {
            $organizationId = app(TenantContext::class)->queryOrganizationId();
            if ($organizationId !== null && empty($model->getAttribute('organization_id'))) {
                $model->setAttribute('organization_id', $organizationId);
            }
        });
    }

    public static function withoutOrganizationTenancy(Closure $callback): mixed
    {
        return app(TenantContext::class)->runWithout($callback);
    }
}
