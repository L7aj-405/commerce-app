<?php

declare(strict_types=1);

namespace App\Livewire\Warehouses;

use App\Models\Warehouse;
use App\Services\Warehouses\WarehouseService;
use Livewire\Component;
use Livewire\Attributes\Layout;
use Illuminate\Support\Facades\Auth;

#[Layout('layouts.app')]
class WarehouseIndex extends Component
{
    public function getWarehouses()
    {
        return Auth::user()->warehouses()
            ->with('stores')
            ->orderBy('is_default', 'desc')
            ->orderBy('name')
            ->get();
    }

    public function deleteWarehouse(string $warehouseId): void
    {
        try {
            $warehouse = Warehouse::findOrFail($warehouseId);
            
            if ($warehouse->user_id !== Auth::id()) {
                $this->dispatch('notify', message: 'Unauthorized', type: 'error');
                return;
            }

            $warehouse->delete();
            $this->dispatch('notify', message: 'Warehouse deleted');
        } catch (\Exception $e) {
            $this->dispatch('notify', message: 'Error deleting warehouse', type: 'error');
        }
    }

    public function setDefault(string $warehouseId): void
    {
        try {
            $warehouse = Warehouse::findOrFail($warehouseId);
            
            if ($warehouse->user_id !== Auth::id()) {
                $this->dispatch('notify', message: 'Unauthorized', type: 'error');
                return;
            }

            $service = new WarehouseService();
            $service->setDefault($warehouse);
            $this->dispatch('notify', message: 'Default warehouse updated');
        } catch (\Exception $e) {
            $this->dispatch('notify', message: 'Error setting default', type: 'error');
        }
    }

    public function render()
    {
        return view('livewire.warehouses.warehouse-index', [
            'warehouses' => $this->getWarehouses(),
        ]);
    }
}