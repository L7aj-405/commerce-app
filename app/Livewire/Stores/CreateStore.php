<?php

declare(strict_types=1);

namespace App\Livewire\Stores;

use App\Models\Store;
use App\Enums\StoreType;
use App\Enums\StoreStatus;
use Livewire\Component;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Attributes\Validate;

#[Layout('layouts.app')]
#[Title('Create Store')]
class CreateStore extends Component
{
    #[Validate('required|string|max:255')]
    public string $name = '';

    #[Validate('nullable|string')]
    public string $description = '';

    #[Validate('required')]
    public string $type = 'online';

    #[Validate('required|string|size:3')]
    public string $currency = 'MAD';

    #[Validate('required|string|size:2')]
    public string $country = 'MA';

    #[Validate('nullable|email')]
    public string $email = '';

    #[Validate('nullable|string|max:20')]
    public string $phone = '';

    #[Validate('nullable|string')]
    public string $address = '';

    public function save(): void
    {
        $this->validate();

        $store = Store::create([
            'user_id' => auth()->id(),
            'name' => $this->name,
            'description' => $this->description,
            'type' => StoreType::from($this->type),
            'status' => StoreStatus::Active,
            'currency' => $this->currency,
            'country' => $this->country,
            'email' => $this->email,
            'phone' => $this->phone,
            'address' => $this->address,
            'settings' => [
                'default_language' => 'en',
                'auto_confirm_orders' => false,
            ],
            'business_rules' => [
                'min_order_value' => 0,
                'require_phone_confirmation' => false,
            ],
        ]);

        session()->flash('success', 'Store created successfully!');

        $this->redirect(route('stores.index'), navigate: true);
    }

    public function render()
    {
        return view('livewire.stores.create-store', [
            'storeTypes' => StoreType::cases(),
        ]);
    }
}