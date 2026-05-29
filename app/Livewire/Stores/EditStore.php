<?php

declare(strict_types=1);

namespace App\Livewire\Stores;

use App\Models\Store;
use App\Enums\StoreType;
use Livewire\Component;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Attributes\Validate;

#[Layout('layouts.app')]
#[Title('Edit Store')]
class EditStore extends Component
{
    public Store $store;

    #[Validate('required|string|max:255')]
    public string $name = '';

    #[Validate('nullable|string')]
    public string $description = '';

    #[Validate('required')]
    public string $type = '';

    #[Validate('required|string|size:3')]
    public string $currency = '';

    #[Validate('required|string|size:2')]
    public string $country = '';

    #[Validate('nullable|email')]
    public string $email = '';

    #[Validate('nullable|string|max:20')]
    public string $phone = '';

    #[Validate('nullable|string')]
    public string $address = '';

    public function mount(Store $store): void
    {
        // Authorization: user must own this store
        abort_unless($store->user_id === auth()->id(), 403);

        $this->store = $store;
        $this->name = $store->name;
        $this->description = $store->description ?? '';
        $this->type = $store->type->value;
        $this->currency = $store->currency;
        $this->country = $store->country;
        $this->email = $store->email ?? '';
        $this->phone = $store->phone ?? '';
        $this->address = $store->address ?? '';
    }

    public function save(): void
    {
        $this->validate();

        $this->store->update([
            'name' => $this->name,
            'description' => $this->description,
            'type' => StoreType::from($this->type),
            'currency' => $this->currency,
            'country' => $this->country,
            'email' => $this->email,
            'phone' => $this->phone,
            'address' => $this->address,
        ]);

        session()->flash('success', 'Store updated successfully!');

        $this->redirect(route('stores.index'), navigate: true);
    }

    public function render()
    {
        return view('livewire.stores.edit-store', [
            'storeTypes' => StoreType::cases(),
        ]);
    }
}