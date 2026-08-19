<?php

declare(strict_types=1);

namespace App\Models\Scopes;

use App\Support\TenantContext;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Scope;

/** Product stays brand-scoped; warehouse visibility follows the organization. */
class TenantInventoryScope implements Scope
{
    public function apply(Builder $builder, Model $model): void
    {
        $context = app(TenantContext::class);
        $storeId = $context->queryStoreId();
        $organizationId = $context->queryOrganizationId();

        if ($storeId === null) {
            if ($context->denyTenantQueriesWhenUnresolved()) $builder->whereRaw('1 = 0');
            return;
        }

        $table = $model->getTable();
        $builder->whereExists(function ($query) use ($table, $storeId): void {
            $query->selectRaw('1')->from('products')
                ->whereColumn('products.id', $table . '.product_id')
                ->where('products.store_id', $storeId);
        });

        if ($organizationId !== null) {
            $builder->whereExists(function ($query) use ($table, $organizationId): void {
                $query->selectRaw('1')->from('warehouses')
                    ->whereColumn('warehouses.id', $table . '.warehouse_id')
                    ->where(function ($q) use ($organizationId): void {
                        $q->where('warehouses.owner_organization_id', $organizationId)
                            ->orWhere('warehouses.operator_organization_id', $organizationId)
                            ->orWhereExists(function ($access) use ($organizationId): void {
                                $access->selectRaw('1')->from('warehouse_organization_access')
                                    ->whereColumn('warehouse_organization_access.warehouse_id', 'warehouses.id')
                                    ->where('warehouse_organization_access.organization_id', $organizationId)
                                    ->where('warehouse_organization_access.is_active', true);
                            });
                    });
            });
            return;
        }

        $builder->whereExists(function ($query) use ($table, $storeId): void {
            $query->selectRaw('1')->from('warehouse_store')
                ->whereColumn('warehouse_store.warehouse_id', $table . '.warehouse_id')
                ->where('warehouse_store.store_id', $storeId);
        });
    }
}
