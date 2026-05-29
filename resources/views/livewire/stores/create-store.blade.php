<div class="space-y-6">

    {{-- Page Header --}}
    <div class="page-header">
        <div>
            <h1 class="page-title">Create Store</h1>
            <p class="page-subtitle">Set up a new online, physical, or hybrid store</p>
        </div>
    </div>

    {{-- Form --}}
    <form wire:submit="save">
        <div class="card space-y-6">

            {{-- Store Name --}}
            <div>
                <label for="name" class="label">Store Name <span class="text-red-500">*</span></label>
                <input type="text" id="name" wire:model="name"
                    placeholder="My Awesome Store"
                    class="input mt-1.5 @error('name') border-red-300 dark:border-red-500 focus:ring-red-500 @enderror">
                @error('name') <p class="error-text mt-1">{{ $message }}</p> @enderror
            </div>

            {{-- Description --}}
            <div>
                <label for="description" class="label">Description</label>
                <textarea id="description" wire:model="description" rows="3"
                    placeholder="Tell us about your store…"
                    class="input mt-1.5 @error('description') border-red-300 dark:border-red-500 @enderror"></textarea>
                @error('description') <p class="error-text mt-1">{{ $message }}</p> @enderror
            </div>

            {{-- Store Type --}}
            <div>
                <p class="label">Store Type <span class="text-red-500">*</span></p>
                <div class="grid grid-cols-1 sm:grid-cols-3 gap-3 mt-1.5">
                    @foreach($storeTypes as $storeType)
                        <label class="relative flex cursor-pointer rounded-xl border-2 p-4 transition-colors
                            {{ $type === $storeType->value
                                ? 'border-blue-500 bg-blue-50 dark:bg-blue-500/10'
                                : 'border-gray-200 dark:border-gray-700 hover:border-gray-300 dark:hover:border-gray-600 bg-white dark:bg-gray-800/50' }}">
                            <input type="radio" wire:model.live="type" value="{{ $storeType->value }}" class="sr-only">
                            <span class="flex flex-1 flex-col gap-1">
                                <span class="text-xl" aria-hidden="true">{{ $storeType->icon() }}</span>
                                <span class="text-sm font-semibold text-gray-900 dark:text-white">{{ $storeType->label() }}</span>
                            </span>
                            @if($type === $storeType->value)
                                <svg class="h-5 w-5 text-blue-600 dark:text-blue-400 flex-shrink-0" viewBox="0 0 20 20" fill="currentColor" aria-hidden="true">
                                    <path fill-rule="evenodd" d="M10 18a8 8 0 100-16 8 8 0 000 16zm3.707-9.293a1 1 0 00-1.414-1.414L9 10.586 7.707 9.293a1 1 0 00-1.414 1.414l2 2a1 1 0 001.414 0l4-4z" clip-rule="evenodd"/>
                                </svg>
                            @endif
                        </label>
                    @endforeach
                </div>
                @error('type') <p class="error-text mt-1">{{ $message }}</p> @enderror
            </div>

            <div class="divider"></div>

            {{-- Currency & Country --}}
            <div class="grid grid-cols-1 sm:grid-cols-2 gap-5">
                <div>
                    <label for="currency" class="label">Currency <span class="text-red-500">*</span></label>
                    <select id="currency" wire:model="currency" class="input mt-1.5">
                        <option value="MAD">MAD — Moroccan Dirham</option>
                        <option value="EUR">EUR — Euro</option>
                        <option value="USD">USD — US Dollar</option>
                    </select>
                    @error('currency') <p class="error-text mt-1">{{ $message }}</p> @enderror
                </div>
                <div>
                    <label for="country" class="label">Country <span class="text-red-500">*</span></label>
                    <select id="country" wire:model="country" class="input mt-1.5">
                        <option value="MA">Morocco</option>
                        <option value="FR">France</option>
                        <option value="US">United States</option>
                    </select>
                    @error('country') <p class="error-text mt-1">{{ $message }}</p> @enderror
                </div>
            </div>

            {{-- Email & Phone --}}
            <div class="grid grid-cols-1 sm:grid-cols-2 gap-5">
                <div>
                    <label for="email" class="label">Store Email</label>
                    <input type="email" id="email" wire:model="email"
                        placeholder="store@example.com"
                        class="input mt-1.5 @error('email') border-red-300 dark:border-red-500 @enderror">
                    @error('email') <p class="error-text mt-1">{{ $message }}</p> @enderror
                </div>
                <div>
                    <label for="phone" class="label">Store Phone</label>
                    <input type="tel" id="phone" wire:model="phone"
                        placeholder="+212 600 000 000"
                        class="input mt-1.5 @error('phone') border-red-300 dark:border-red-500 @enderror">
                    @error('phone') <p class="error-text mt-1">{{ $message }}</p> @enderror
                </div>
            </div>

            {{-- Address (Physical / Hybrid only) --}}
            @if($type === 'physical' || $type === 'hybrid')
                <div>
                    <label for="address" class="label">Physical Address</label>
                    <textarea id="address" wire:model="address" rows="3"
                        placeholder="Street address, city, postal code…"
                        class="input mt-1.5 @error('address') border-red-300 dark:border-red-500 @enderror"></textarea>
                    @error('address') <p class="error-text mt-1">{{ $message }}</p> @enderror
                </div>
            @endif

            {{-- Actions --}}
            <div class="flex items-center justify-end gap-3 pt-2 border-t border-gray-100 dark:border-gray-700">
                <a href="{{ route('stores.index') }}" wire:navigate class="btn btn-secondary">
                    Cancel
                </a>
                <button type="submit" class="btn btn-primary">
                    <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="1.7" aria-hidden="true">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M19 21V5a2 2 0 00-2-2H7a2 2 0 00-2 2v16m14 0h2m-2 0h-5m-9 0H3m2 0h5M9 7h1m-1 4h1m4-4h1m-1 4h1m-5 10v-5a1 1 0 011-1h2a1 1 0 011 1v5m-4 0h4"/>
                    </svg>
                    Create Store
                </button>
            </div>

        </div>
    </form>

</div>
