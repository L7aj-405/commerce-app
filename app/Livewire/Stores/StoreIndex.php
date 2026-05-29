<?php

declare(strict_types=1);

namespace App\Livewire\Stores;

use App\Models\Store;
use Livewire\Component;
use Livewire\WithPagination;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use App\Enums\StoreType;
use App\Enums\StoreStatus;

#[Layout('layouts.app')]
#[Title('My Stores')]
class StoreIndex extends Component
{
    use WithPagination;

    public string $search = '';
    public string $typeFilter = '';
    public string $statusFilter = '';

    public function updatingSearch(): void
    {
        $this->resetPage();
    }

    public function updatingTypeFilter(): void
    {
        $this->resetPage();
    }

    public function updatingStatusFilter(): void
    {
        $this->resetPage();
    }

    public function deleteStore(string $storeId): void
    {
        $store = Store::where('id', $storeId)
            ->where('user_id', auth()->id())
            ->firstOrFail();

        $store->delete();

        session()->flash('success', 'Store deleted successfully!');
    }

    public function render()
    {
        $query = Store::where('user_id', auth()->id())
            ->latest('created_at');

        if ($this->search) {
            $query->where('name', 'like', '%' . $this->search . '%');
        }

        if ($this->typeFilter) {
            $query->where('type', $this->typeFilter);
        }

        if ($this->statusFilter) {
            $query->where('status', $this->statusFilter);
        }

        return view('livewire.stores.store-index', [
            'stores' => $query->paginate(10),
            'storeTypes' => StoreType::cases(),
            'storeStatuses' => StoreStatus::cases(),
        ]);
    }
}