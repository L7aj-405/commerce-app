<?php

declare(strict_types=1);

namespace App\Repositories;

use App\Models\Facture;
use App\Models\PosOrder;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Collection;

/**
 * Read-side queries for invoices, always scoped by store_id. Keeping these out
 * of the controller and service keeps both thin and the tenancy filter in one place.
 */
class InvoiceRepository
{
    /**
     * @param  array{status?: string, search?: string}  $filters
     */
    public function paginateForStore(string $storeId, array $filters = [], int $perPage = 20): LengthAwarePaginator
    {
        return Facture::query()
            ->where('store_id', $storeId)
            ->when($filters['status'] ?? null, fn ($q, $status) => $q->where('status', $status))
            ->when($filters['search'] ?? null, fn ($q, $search) => $q->where(function ($q) use ($search) {
                $q->where('invoice_number', 'like', "%{$search}%")
                    ->orWhere('customer_name', 'like', "%{$search}%");
            }))
            ->latest('invoice_date')
            ->paginate($perPage)
            ->withQueryString();
    }

    public function findForStore(string $storeId, string $id): ?Facture
    {
        return Facture::query()
            ->where('store_id', $storeId)
            ->with(['items', 'issuedBy:id,name'])
            ->find($id);
    }

    /**
     * POS orders in this store that have not yet been invoiced — the candidates
     * for the "Generate Invoice" (deferred invoicing) dashboard action.
     *
     * @return Collection<int, PosOrder>
     */
    public function uninvoicedPosOrders(string $storeId, int $limit = 50): Collection
    {
        return PosOrder::query()
            ->where('store_id', $storeId)
            ->whereDoesntHave('invoice')
            ->latest()
            ->limit($limit)
            ->get();
    }
}
