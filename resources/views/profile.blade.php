<x-app-layout>
    <x-slot name="header">
        <h2 class="font-semibold text-xl text-gray-800 leading-tight">
            {{ __('Profile') }}
        </h2>
    </x-slot>

    <div class="py-12">
        <div class="max-w-3xl mx-auto sm:px-6 lg:px-8"
             x-data="{ tab: 'profile' }">

            {{-- Tab navigation --}}
            <div class="border-b border-gray-200 mb-6">
                <nav class="-mb-px flex space-x-6" aria-label="Profile tabs">
                    <button @click="tab = 'profile'"
                        :class="tab === 'profile' ? 'border-indigo-500 text-indigo-600' : 'border-transparent text-gray-500 hover:text-gray-700 hover:border-gray-300'"
                        class="whitespace-nowrap py-3 px-1 border-b-2 font-medium text-sm transition-colors">
                        Profile
                    </button>
                    <button @click="tab = 'password'"
                        :class="tab === 'password' ? 'border-indigo-500 text-indigo-600' : 'border-transparent text-gray-500 hover:text-gray-700 hover:border-gray-300'"
                        class="whitespace-nowrap py-3 px-1 border-b-2 font-medium text-sm transition-colors">
                        Password
                    </button>
                    <button @click="tab = 'preferences'"
                        :class="tab === 'preferences' ? 'border-indigo-500 text-indigo-600' : 'border-transparent text-gray-500 hover:text-gray-700 hover:border-gray-300'"
                        class="whitespace-nowrap py-3 px-1 border-b-2 font-medium text-sm transition-colors">
                        Preferences
                    </button>
                    <button @click="tab = 'danger'"
                        :class="tab === 'danger' ? 'border-red-500 text-red-600' : 'border-transparent text-gray-500 hover:text-red-500 hover:border-red-300'"
                        class="whitespace-nowrap py-3 px-1 border-b-2 font-medium text-sm transition-colors">
                        Danger Zone
                    </button>
                </nav>
            </div>

            {{-- Tab panels --}}
            <div class="bg-white shadow sm:rounded-lg p-6 sm:p-8">
                <div x-show="tab === 'profile'">
                    <livewire:profile.profile-information />
                </div>

                <div x-show="tab === 'password'">
                    <livewire:profile.update-password />
                </div>

                <div x-show="tab === 'preferences'">
                    <livewire:profile.user-preferences />
                </div>

                <div x-show="tab === 'danger'">
                    <livewire:profile.delete-account />
                </div>
            </div>

        </div>
    </div>
</x-app-layout>
