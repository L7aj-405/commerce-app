<?php

declare(strict_types=1);

namespace App\Livewire\Warehouses;

use App\Services\Warehouses\WarehouseService;
use Livewire\Component;
use Livewire\Attributes\Layout;
use Illuminate\Support\Facades\Auth;

#[Layout('layouts.app')]
class WarehouseCreate extends Component
{
    public string $name = '';
    public string $location = '';
    public string $address = '';
    public string $city = '';
    public string $state = '';
    public string $country = '';
    public string $zip = '';
    public string $phone = '';
    public string $email = '';
    public bool $is_active = true;
    public bool $is_default = false;

    protected $rules = [
        'name' => 'required|string|min:3',
        'location' => 'nullable|string',
        'address' => 'nullable|string',
        'city' => 'nullable|string',
        'state' => 'nullable|string',
        'country' => 'nullable|string',
        'zip' => 'nullable|string',
        'phone' => 'nullable|string',
        'email' => 'nullable|email',
    ];

    public function create(): void
    {
        $this->validate();

        $service = new WarehouseService();
        $service->create(Auth::user(), [
            'name' => $this->name,
            'location' => $this->location,
            'address' => $this->address,
            'city' => $this->city,
            'state' => $this->state,
            'country' => $this->country,
            'zip' => $this->zip,
            'phone' => $this->phone,
            'email' => $this->email,
            'is_active' => $this->is_active,
            'is_default' => $this->is_default,
        ]);

        $this->dispatch('notify', message: 'Warehouse created successfully');
        $this->redirect(route('warehouses.index'));
    }

    public function render()
    {
        return view('livewire.warehouses.warehouse-create');
    }
}