<?php

namespace App\Livewire\Products;

use App\Models\Store;
use App\Models\Warehouse;
use Livewire\Attributes\Layout;
use App\Services\Products\ProductService;
use Livewire\Component;
use Illuminate\Support\Facades\Auth;

#[Layout('layouts.app')]
class ProductCreate extends Component
{
    public Store $store;
    
    public string $name = '';
    public string $sku = '';
    public string $type = 'simple';
    public string $description = '';
    public float|int $price = 0;
    public float|int $cost = 0;
    public string $warehouse_id = '';
    public int $quantity = 0;

    protected array $rules = [
        'name' => 'required|string|max:255',
        'sku' => 'required|string|max:255|unique:products,sku',
        'type' => 'required|in:simple,variable',
        'price' => 'required|numeric|min:0',
        'cost' => 'required|numeric|min:0',
        'warehouse_id' => 'nullable|string',
        'quantity' => 'required|integer|min:0',
    ];

    public function mount(Store $store)
    {
        $this->store = $store;
        
        // Set first warehouse as default if available
        $warehouse = $store->getPrimaryWarehouse();
        if ($warehouse) {
            $this->warehouse_id = $warehouse->id;
        } else {
            // Get first active warehouse
            $firstWarehouse = Auth::user()->warehouses()->first();
            if ($firstWarehouse) {
                $this->warehouse_id = $firstWarehouse->id;
            }
        }
    }

    public function create()
    {
        $this->validate();

        $service = app(ProductService::class);

        try {
            $service->create($this->store, [
                'name' => $this->name,
                'sku' => $this->sku,
                'type' => $this->type,
                'description' => $this->description,
                'price' => (float) $this->price,
                'cost' => (float) $this->cost,
                'warehouse_id' => $this->warehouse_id ?: null,
                'quantity' => (int) $this->quantity,
            ]);

            session()->flash('success', 'Product created successfully');
            $this->redirect(route('stores.products.index', $this->store));
        } catch (\Exception $e) {
            session()->flash('error', 'Error creating product: ' . $e->getMessage());
        }
    }

    public function render()
    {
        return view('livewire.products.product-create', [
            'warehouses' => Auth::user()->warehouses()->where('is_active', true)->get(),
        ]);
    }
}