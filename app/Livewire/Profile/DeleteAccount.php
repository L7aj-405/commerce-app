<?php

declare(strict_types=1);

namespace App\Livewire\Profile;

use App\Livewire\Actions\Logout;
use Illuminate\Support\Facades\Hash;
use Livewire\Attributes\Validate;
use Livewire\Component;

class DeleteAccount extends Component
{
    #[Validate('required|string')]
    public string $password = '';

    public function deleteAccount(Logout $logout): void
    {
        $this->validate();

        $user = auth()->user();

        if (! Hash::check($this->password, $user->password)) {
            $this->addError('password', 'The password is incorrect.');
            return;
        }

        $user->delete();

        $logout();

        $this->redirect(route('home'), navigate: false);
    }

    public function render(): string
    {
        return <<<'blade'
        <div>
            <header class="mb-6">
                <h2 class="text-lg font-medium text-gray-900">Delete Account</h2>
                <p class="mt-1 text-sm text-gray-600">Permanently delete your account and all associated data.</p>
            </header>

            <div class="mb-6 p-4 bg-red-50 border border-red-200 rounded-md">
                <p class="text-sm font-medium text-red-800">Warning: This action is irreversible.</p>
                <p class="mt-1 text-sm text-red-700">All your stores and data will be deleted and cannot be recovered.</p>
            </div>

            <form wire:submit="deleteAccount" class="space-y-4">
                <div>
                    <label for="delete_password" class="block text-sm font-medium text-gray-700">Confirm your password</label>
                    <input wire:model="password" id="delete_password" type="password"
                        class="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-red-500 focus:ring-red-500 sm:text-sm" />
                    @error('password') <p class="mt-1 text-sm text-red-600">{{ $message }}</p> @enderror
                </div>

                <div class="flex items-center gap-4 pt-2">
                    <button type="submit"
                        class="px-4 py-2 bg-red-600 text-white text-sm font-medium rounded-md hover:bg-red-700 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-red-500">
                        Delete Account
                    </button>
                </div>
            </form>
        </div>
        blade;
    }
}
