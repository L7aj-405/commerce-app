<?php

declare(strict_types=1);

namespace App\Livewire\Orders;

use App\Enums\OrderStatus;
use App\Models\Order;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Builder;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Component;
use Livewire\WithPagination;

#[Layout('layouts.app')]
#[Title('Orders')]
class OrderIndex extends Component
{
    use WithPagination;

    public string $search         = '';
    public string $storeFilter    = '';
    public string $statusFilter   = '';
    public string $platformFilter = '';
    public string $dateFilter     = 'all';

    public function updatingSearch(): void         { $this->resetPage(); }
    public function updatingStoreFilter(): void    { $this->resetPage(); }
    public function updatingStatusFilter(): void   { $this->resetPage(); }
    public function updatingPlatformFilter(): void { $this->resetPage(); }
    public function updatingDateFilter(): void     { $this->resetPage(); }

    public function markAsConfirmed(string $orderId): void
    {
        $this->findOrder($orderId)->markAsConfirmed();
        session()->flash('success', 'Order confirmed.');
    }

    public function markAsCancelled(string $orderId): void
    {
        $this->findOrder($orderId)->markAsCancelled();
        session()->flash('success', 'Order cancelled.');
    }

    private function findOrder(string $id): Order
    {
        $order = Order::whereHas('store', fn (Builder $q) => $q->where('user_id', auth()->id()))
            ->find($id);

        abort_if($order === null, 403);

        return $order;
    }

    private function filteredQuery(): Builder
    {
        $storeIds = auth()->user()->stores()->pluck('id');

        $query = Order::whereIn('store_id', $storeIds)->latest();

        if ($this->search) {
            $query->where(function (Builder $q): void {
                $q->where('order_number', 'like', '%' . $this->search . '%')
                  ->orWhere('customer_name', 'like', '%' . $this->search . '%');
            });
        }

        if ($this->storeFilter) {
            $query->where('store_id', $this->storeFilter);
        }

        if ($this->statusFilter) {
            $query->where('status', $this->statusFilter);
        }

        if ($this->platformFilter) {
            $query->whereHas('platformConnection', fn (Builder $q) => $q->where('platform', $this->platformFilter));
        }

        match ($this->dateFilter) {
            'today' => $query->whereDate('created_at', today()),
            'week'  => $query->where('created_at', '>=', now()->subDays(7)),
            'month' => $query->where('created_at', '>=', now()->subDays(30)),
            default => null,
        };

        return $query;
    }

    public function render(): View
    {
        $baseQuery    = $this->filteredQuery();
        $totalRevenue = number_format((float) (clone $baseQuery)->sum('total'), 2);
        $orders       = (clone $baseQuery)->with(['store', 'platformConnection'])->paginate(15);

        return view('livewire.orders.order-index', [
            'orders'        => $orders,
            'totalRevenue'  => $totalRevenue,
            'stores'        => auth()->user()->stores()->orderBy('name')->get(),
            'orderStatuses' => OrderStatus::cases(),
        ]);
    }
}
