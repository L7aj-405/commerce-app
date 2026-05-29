<?php

namespace App\Livewire\Warehouses;

use App\Models\Warehouse;
use App\Models\Store;
use App\Services\Warehouses\WarehouseService;
use Livewire\Component;
use Livewire\Attributes\Layout;
use Illuminate\Support\Facades\Auth;

#[Layout('layouts.app')]
class WarehouseEdit extends Component
{
    public Warehouse $warehouse;
    
    public string $name = '';
    public string $location = '';
    public string $address = '';
    public string $city = '';
    public string $state = '';
    public string $country = '';
    public string $zip = '';
    public bool $is_active = true;
    
    public array $selectedStores = [];
    public array $primaryStore = [];
    
    protected WarehouseService $service;

    public function mount(Warehouse $warehouse)
    {
        $this->warehouse = $warehouse;
        $this->name = $warehouse->name;
        $this->location = $warehouse->location;
        $this->address = $warehouse->address;
        $this->city = $warehouse->city;
        $this->state = $warehouse->state;
        $this->country = $warehouse->country;
        $this->zip = $warehouse->zip;
        $this->is_active = $warehouse->is_active;
        
        // Load assigned stores
        $assignedStores = $warehouse->stores()->pluck('store_id')->toArray();
        $this->selectedStores = $assignedStores;
        
        // Load primary stores
        $primaryStores = $warehouse->stores()
            ->wherePivot('is_primary', true)
            ->pluck('store_id')
            ->toArray();
        
        foreach ($primaryStores as $storeId) {
            $this->primaryStore[$storeId] = true;
        }
    }

    public function save()
    {
        $this->validate([
            'name' => 'required|string|max:255',
        ]);

        $this->service = app(WarehouseService::class);

        $this->service->update($this->warehouse, [
            'name' => $this->name,
            'location' => $this->location,
            'address' => $this->address,
            'city' => $this->city,
            'state' => $this->state,
            'country' => $this->country,
            'zip' => $this->zip,
            'is_active' => $this->is_active,
        ]);

        session()->flash('success', 'Warehouse updated successfully');
    }

    public function updateStoreAssignment()
    {
        $this->service = app(WarehouseService::class);

        // Get all stores for this user
        $allStores = Auth::user()->stores()->pluck('id')->toArray();

        // Detach stores that were unselected
        foreach ($allStores as $storeId) {
            if (!in_array($storeId, $this->selectedStores)) {
                $this->warehouse->stores()->detach($storeId);
            }
        }

        // Attach/update selected stores
        foreach ($this->selectedStores as $storeId) {
            $isPrimary = isset($this->primaryStore[$storeId]) && $this->primaryStore[$storeId];
            
            // Use attach with pivot data (won't error if already exists)
            $this->warehouse->stores()->syncWithoutDetaching([
                $storeId => [
                    'is_primary' => $isPrimary,
                    'priority' => $isPrimary ? 1 : 0,
                ]
            ]);
        }

        session()->flash('success', 'Store assignments updated');
    }

    public function render()
    {
        return view('livewire.warehouses.warehouse-edit', [
            'stores' => Auth::user()->stores()->get(),
        ]);
    }
}