<?php

declare(strict_types=1);

namespace App\Models\Scopes;

use App\Support\TenantContext;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Scope;

/** Tenant scope for rows belonging to a Product which carries store_id. */
class TenantThroughProductScope implements Scope
{
    public function apply(Builder $builder, Model $model): void
    {
        $context = app(TenantContext::class);
        $storeId = $context->queryStoreId();

        if ($storeId === null) {
            if ($context->denyTenantQueriesWhenUnresolved()) {
                $builder->whereRaw('1 = 0');
            }
            return;
        }

        $table = $model->getTable();

        $builder->whereExists(function ($query) use ($table, $storeId): void {
            $query->selectRaw('1')
                ->from('products')
                ->whereColumn('products.id', $table . '.product_id')
                ->where('products.store_id', $storeId);
        });
    }
}
