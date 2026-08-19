<?php

declare(strict_types=1);

namespace App\Models\Scopes;

use App\Support\TenantContext;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Scope;

/** Global scope that constrains a direct store-owned model to the active Store. */
class TenantScope implements Scope
{
    public function apply(Builder $builder, Model $model): void
    {
        $context = app(TenantContext::class);
        $storeId = $context->queryStoreId();

        if ($storeId !== null) {
            $builder->where($model->getTable() . '.store_id', $storeId);
            return;
        }

        if ($context->denyTenantQueriesWhenUnresolved()) {
            $builder->whereRaw('1 = 0');
        }
    }
}
