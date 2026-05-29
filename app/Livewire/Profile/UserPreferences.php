<?php

declare(strict_types=1);

namespace App\Livewire\Profile;

use Livewire\Attributes\Validate;
use Livewire\Component;

class UserPreferences extends Component
{
    #[Validate('required|string|in:en,fr,ar')]
    public string $language = 'en';

    #[Validate('required|string|timezone')]
    public string $timezone = 'UTC';

    #[Validate('boolean')]
    public bool $notifications = true;

    public function mount(): void
    {
        $settings            = auth()->user()->settings ?? [];
        $this->language      = $settings['language'] ?? 'en';
        $this->timezone      = $settings['timezone'] ?? 'Africa/Casablanca';
        $this->notifications = (bool) ($settings['notifications'] ?? true);
    }

    public function savePreferences(): void
    {
        $this->validate();

        $user = auth()->user();
        $user->settings = array_merge($user->settings ?? [], [
            'language'      => $this->language,
            'timezone'      => $this->timezone,
            'notifications' => $this->notifications,
        ]);
        $user->save();

        session()->flash('preferences_success', 'Preferences saved.');
    }

    public function render(): string
    {
        return <<<'blade'
        <div>
            <header class="mb-6">
                <h2 class="text-lg font-medium text-gray-900">Preferences</h2>
                <p class="mt-1 text-sm text-gray-600">Manage your language, timezone, and notification settings.</p>
            </header>

            @if (session('preferences_success'))
                <div class="mb-4 p-4 bg-green-50 border border-green-200 rounded-md text-sm text-green-700">
                    {{ session('preferences_success') }}
                </div>
            @endif

            <form wire:submit="savePreferences" class="space-y-4">
                <div>
                    <label for="language" class="block text-sm font-medium text-gray-700">Language</label>
                    <select wire:model="language" id="language"
                        class="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500 sm:text-sm">
                        <option value="en">English</option>
                        <option value="fr">Français</option>
                        <option value="ar">العربية</option>
                    </select>
                    @error('language') <p class="mt-1 text-sm text-red-600">{{ $message }}</p> @enderror
                </div>

                <div>
                    <label for="timezone" class="block text-sm font-medium text-gray-700">Timezone</label>
                    <select wire:model="timezone" id="timezone"
                        class="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500 sm:text-sm">
                        <optgroup label="UTC">
                            <option value="UTC">UTC</option>
                        </optgroup>
                        <optgroup label="Americas">
                            <option value="America/New_York">Eastern Time (US)</option>
                            <option value="America/Chicago">Central Time (US)</option>
                            <option value="America/Denver">Mountain Time (US)</option>
                            <option value="America/Los_Angeles">Pacific Time (US)</option>
                            <option value="America/Sao_Paulo">São Paulo</option>
                        </optgroup>
                        <optgroup label="Europe">
                            <option value="Europe/London">London</option>
                            <option value="Europe/Paris">Paris</option>
                            <option value="Europe/Berlin">Berlin</option>
                            <option value="Europe/Madrid">Madrid</option>
                        </optgroup>
                        <optgroup label="Africa & Middle East">
                            <option value="Africa/Casablanca">Casablanca</option>
                            <option value="Africa/Cairo">Cairo</option>
                            <option value="Asia/Riyadh">Riyadh</option>
                            <option value="Asia/Dubai">Dubai</option>
                        </optgroup>
                        <optgroup label="Asia & Pacific">
                            <option value="Asia/Kolkata">India</option>
                            <option value="Asia/Singapore">Singapore</option>
                            <option value="Asia/Tokyo">Tokyo</option>
                            <option value="Australia/Sydney">Sydney</option>
                        </optgroup>
                    </select>
                    @error('timezone') <p class="mt-1 text-sm text-red-600">{{ $message }}</p> @enderror
                </div>

                <div class="flex items-center gap-3">
                    <input wire:model="notifications" id="notifications" type="checkbox"
                        class="h-4 w-4 rounded border-gray-300 text-indigo-600 focus:ring-indigo-500" />
                    <label for="notifications" class="text-sm font-medium text-gray-700">Enable notifications</label>
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
