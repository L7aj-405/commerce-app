<?php

declare(strict_types=1);

namespace App\Models\Scopes;

use App\Support\TenantContext;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Scope;

/** Warehouse visibility follows the organization workspace in V2. */
class TenantWarehouseScope implements Scope
{
    public function apply(Builder $builder, Model $model): void
    {
        $context = app(TenantContext::class);
        $storeId = $context->queryStoreId();
        $organizationId = $context->queryOrganizationId();

        if ($organizationId !== null) {
            $table = $model->getTable();
            $builder->where(function (Builder $q) use ($table, $organizationId): void {
                $q->where($table . '.owner_organization_id', $organizationId)
                    ->orWhere($table . '.operator_organization_id', $organizationId)
                    ->orWhereExists(function ($access) use ($table, $organizationId): void {
                        $access->selectRaw('1')->from('warehouse_organization_access')
                            ->whereColumn('warehouse_organization_access.warehouse_id', $table . '.id')
                            ->where('warehouse_organization_access.organization_id', $organizationId)
                            ->where('warehouse_organization_access.is_active', true);
                    });
            });
            return;
        }

        if ($storeId === null) {
            if ($context->denyTenantQueriesWhenUnresolved()) $builder->whereRaw('1 = 0');
            return;
        }

        $table = $model->getTable();
        $builder->whereExists(function ($query) use ($table, $storeId): void {
            $query->selectRaw('1')->from('warehouse_store')
                ->whereColumn('warehouse_store.warehouse_id', $table . '.id')
                ->where('warehouse_store.store_id', $storeId);
        });
    }
}
