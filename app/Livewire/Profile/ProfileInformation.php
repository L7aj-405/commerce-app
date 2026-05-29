<?php

declare(strict_types=1);

namespace App\Livewire\Profile;

use Livewire\Component;

class ProfileInformation extends Component
{
    public string $name = '';
    public string $email = '';
    public ?string $phone = null;

    public function mount(): void
    {
        $user = auth()->user();
        $this->name = $user->name;
        $this->email = $user->email;
        $this->phone = $user->phone;
    }

    public function rules(): array
    {
        return [
            'name'  => 'required|string|max:255',
            'email' => 'required|email|max:255|unique:users,email,' . auth()->user()->id,
            'phone' => 'nullable|string|max:20',
        ];
    }

    public function updateProfile(): void
    {
        $this->validate();

        $user = auth()->user();
        $emailChanged = $this->email !== $user->email;

        $user->name  = $this->name;
        $user->phone = $this->phone;

        if ($emailChanged) {
            $user->email              = $this->email;
            $user->email_verified_at  = null;
            $user->save();
            $user->sendEmailVerificationNotification();
        } else {
            $user->save();
        }

        session()->flash('profile_success', 'Profile updated.');
    }

    public function render(): string
    {
        return <<<'blade'
        <div>
            <header class="mb-6">
                <h2 class="text-lg font-medium text-gray-900">Profile Information</h2>
                <p class="mt-1 text-sm text-gray-600">Update your name, email address, and phone number.</p>
            </header>

            @if (session('profile_success'))
                <div class="mb-4 p-4 bg-green-50 border border-green-200 rounded-md text-sm text-green-700">
                    {{ session('profile_success') }}
                </div>
            @endif

            @if (! auth()->user()->hasVerifiedEmail())
                <div class="mb-4 p-4 bg-yellow-50 border border-yellow-200 rounded-md text-sm text-yellow-700">
                    Your email address is unverified. Please check your inbox for a verification link.
                </div>
            @endif

            <form wire:submit="updateProfile" class="space-y-4">
                <div>
                    <label for="name" class="block text-sm font-medium text-gray-700">Name</label>
                    <input wire:model="name" id="name" type="text"
                        class="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500 sm:text-sm" />
                    @error('name') <p class="mt-1 text-sm text-red-600">{{ $message }}</p> @enderror
                </div>

                <div>
                    <label for="email" class="block text-sm font-medium text-gray-700">Email</label>
                    <input wire:model="email" id="email" type="email"
                        class="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500 sm:text-sm" />
                    @error('email') <p class="mt-1 text-sm text-red-600">{{ $message }}</p> @enderror
                </div>

                <div>
                    <label for="phone" class="block text-sm font-medium text-gray-700">Phone</label>
                    <input wire:model="phone" id="phone" type="tel"
                        class="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500 sm:text-sm" />
                    @error('phone') <p class="mt-1 text-sm text-red-600">{{ $message }}</p> @enderror
                </div>

                <div class="flex items-center gap-4 pt-2">
                    <button type="submit"
                        class="px-4 py-2 bg-gray-800 text-white text-sm font-medium rounded-md hover:bg-gray-700 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-gray-500">
                        Save
                    </button>
                </div>
            </form>
        </div>
        blade;
    }
}
