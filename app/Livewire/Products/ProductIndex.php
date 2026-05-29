<?php

declare(strict_types=1);

namespace App\Livewire\Products;

use App\Models\Product;
use App\Models\Store;
use App\Models\SyncLog;
use Livewire\Component;
use Livewire\Attributes\Layout;
use Livewire\Attributes\On;
use Livewire\WithPagination;

#[Layout('layouts.app')]
class ProductIndex extends Component
{
    use WithPagination;

    public Store $store;
    public string $search = '';
    public string $status = '';
    public string $type = '';

    public function mount(Store $store): void
    {
        $this->store = $store;
    }

    public function updatingSearch(): void
    {
        $this->resetPage();
    }

    public function updatingStatus(): void
    {
        $this->resetPage();
    }

    public function updatingType(): void
    {
        $this->resetPage();
    }

    public function deleteProduct(string $productId): void
    {
        try {
            $product = Product::findOrFail($productId);
            $product->delete();
            $this->dispatch('notify', message: 'Product deleted successfully');
        } catch (\Exception $e) {
            $this->dispatch('notify', message: 'Error deleting product', type: 'error');
        }
    }

    #[On('sync-completed')]
    public function onSyncCompleted(): void
    {
        $this->resetPage();
    }

    public function duplicateProduct(string $productId): void
    {
        try {
            $product = Product::findOrFail($productId);
            $service = new \App\Services\Products\ProductService();
            $service->duplicate($product);
            $this->dispatch('notify', message: 'Product duplicated successfully');
        } catch (\Exception $e) {
            $this->dispatch('notify', message: 'Error duplicating product', type: 'error');
        }
    }

    public function getProducts()
    {
        $query = $this->store->products();

        if ($this->search) {
            $search = $this->search;
            $query->where(function ($q) use ($search) {
                $q->where('name', 'like', "%$search%")
                    ->orWhere('sku', 'like', "%$search%");
            });
        }

        if ($this->status) {
            $query->where('status', $this->status);
        }

        if ($this->type) {
            $query->where('type', $this->type);
        }

        return $query->with(['variants', 'stocks'])
            ->withCount('variants')
            ->orderBy('created_at', 'desc')
            ->paginate(50);
    }

    public function render()
    {
        $lastSync = SyncLog::where('store_id', $this->store->id)
            ->where('direction', 'pull')
            ->whereNotNull('completed_at')
            ->latest('completed_at')
            ->first()
            ?->completed_at;

        return view('livewire.products.product-index', [
            'products'     => $this->getProducts(),
            'lastSyncTime' => $lastSync?->diffForHumans(),
        ]);
    }
}