<div class="space-y-6" x-data="{ tab: 'basic' }">

    {{-- Page Header --}}
    <div class="page-header">
        <div>
            <div class="flex items-center gap-1.5 text-sm text-gray-400 dark:text-gray-500 mb-2">
                <a href="{{ route('stores.products.index', $store) }}"
                   class="hover:text-gray-600 dark:hover:text-gray-300 transition-colors">Products</a>
                <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="2" aria-hidden="true">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M9 5l7 7-7 7"/>
                </svg>
                <span class="text-gray-600 dark:text-gray-400">Edit</span>
            </div>
            <h1 class="page-title">{{ $product->name }}</h1>
            <div class="flex items-center gap-2 mt-2">
                @if($product->sku)
                    <span class="text-xs text-gray-400 dark:text-gray-500 font-mono">SKU: {{ $product->sku }}</span>
                @endif
                <span class="badge
                    {{ $product->status === 'active' ? 'badge-green' : ($product->status === 'draft' ? 'badge-yellow' : 'badge-gray') }}">
                    {{ ucfirst($product->status) }}
                </span>
                <span class="badge badge-indigo">{{ ucfirst($product->type) }}</span>
            </div>
        </div>
        <a href="{{ route('stores.products.index', $store) }}" class="btn-secondary flex-shrink-0">
            <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="1.7" aria-hidden="true">
                <path stroke-linecap="round" stroke-linejoin="round" d="M10 19l-7-7m0 0l7-7m-7 7h18"/>
            </svg>
            Back to Products
        </a>
    </div>

    {{-- Tab Card --}}
    <div class="bg-white dark:bg-gray-800 rounded-2xl border border-gray-200 dark:border-gray-700">

        {{-- Tab Nav --}}
        <div class="border-b border-gray-200 dark:border-gray-700 px-6">
            <nav class="flex gap-0 -mb-px" aria-label="Product sections">

                <button @click="tab = 'basic'"
                        :class="tab === 'basic'
                            ? 'border-blue-600 text-blue-600 dark:text-blue-400 dark:border-blue-500'
                            : 'border-transparent text-gray-500 dark:text-gray-400 hover:text-gray-700 dark:hover:text-gray-200 hover:border-gray-300 dark:hover:border-gray-600'"
                        class="inline-flex items-center gap-2 px-5 py-4 text-sm font-medium border-b-2 transition-colors whitespace-nowrap"
                        :aria-selected="tab === 'basic'"
                        role="tab">
                    <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="1.7" aria-hidden="true">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M9 5H7a2 2 0 00-2 2v12a2 2 0 002 2h10a2 2 0 002-2V7a2 2 0 00-2-2h-2M9 5a2 2 0 002 2h2a2 2 0 002-2M9 5a2 2 0 012-2h2a2 2 0 012 2"/>
                    </svg>
                    Basic Info
                </button>

                @if($product->isVariable())
                    <button @click="tab = 'variants'"
                            :class="tab === 'variants'
                                ? 'border-blue-600 text-blue-600 dark:text-blue-400 dark:border-blue-500'
                                : 'border-transparent text-gray-500 dark:text-gray-400 hover:text-gray-700 dark:hover:text-gray-200 hover:border-gray-300 dark:hover:border-gray-600'"
                            class="inline-flex items-center gap-2 px-5 py-4 text-sm font-medium border-b-2 transition-colors whitespace-nowrap"
                            :aria-selected="tab === 'variants'"
                            role="tab">
                        <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="1.7" aria-hidden="true">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M7 21a4 4 0 01-4-4V5a2 2 0 012-2h4a2 2 0 012 2v12a4 4 0 01-4 4zm0 0h12a2 2 0 002-2v-4a2 2 0 00-2-2h-2.343M11 7.343l1.657-1.657a2 2 0 012.828 0l2.829 2.829a2 2 0 010 2.828l-8.486 8.485M7 17h.01"/>
                        </svg>
                        Variants
                        @if($variantCount > 0)
                            <span class="badge badge-indigo">{{ $variantCount }}</span>
                        @endif
                    </button>
                @endif

                <button @click="tab = 'stock'"
                        :class="tab === 'stock'
                            ? 'border-blue-600 text-blue-600 dark:text-blue-400 dark:border-blue-500'
                            : 'border-transparent text-gray-500 dark:text-gray-400 hover:text-gray-700 dark:hover:text-gray-200 hover:border-gray-300 dark:hover:border-gray-600'"
                        class="inline-flex items-center gap-2 px-5 py-4 text-sm font-medium border-b-2 transition-colors whitespace-nowrap"
                        :aria-selected="tab === 'stock'"
                        role="tab">
                    <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="1.7" aria-hidden="true">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M20 7l-8-4-8 4m16 0l-8 4m8-4v10l-8 4m0-10L4 7m8 4v10M4 7v10l8 4"/>
                    </svg>
                    Stock
                </button>

            </nav>
        </div>

        {{-- Basic Info Tab --}}
        <div x-show="tab === 'basic'"
             x-transition:enter="transition-opacity duration-150"
             x-transition:enter-start="opacity-0"
             x-transition:enter-end="opacity-100"
             class="p-8">
            <form wire:submit="save" class="space-y-8">

                {{-- Basic Information --}}
                <div>
                    <h2 class="text-sm font-semibold text-gray-900 dark:text-white mb-5 flex items-center gap-2">
                        <span class="w-1 h-4 bg-blue-600 rounded-full"></span>
                        Basic Information
                    </h2>
                    <div class="space-y-4">
                        <div>
                            <label for="product-name" class="label">Product Name <span class="text-red-500" aria-hidden="true">*</span></label>
                            <input id="product-name" type="text" wire:model="name"
                                   placeholder="e.g., Premium Cotton T-Shirt"
                                   class="input" autocomplete="off">
                            @error('name') <p class="error-text">{{ $message }}</p> @enderror
                        </div>

                        <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                            <div>
                                <label for="product-sku" class="label">SKU <span class="text-red-500" aria-hidden="true">*</span></label>
                                <input id="product-sku" type="text" wire:model="sku"
                                       placeholder="e.g., TS-001"
                                       class="input font-mono">
                                @error('sku') <p class="error-text">{{ $message }}</p> @enderror
                            </div>
                            <div>
                                <label for="product-type" class="label">Product Type</label>
                                <select id="product-type" wire:model="type" class="input">
                                    <option value="simple">Simple Product</option>
                                    <option value="variable">Variable Product</option>
                                </select>
                            </div>
                        </div>

                        <div>
                            <label for="product-description" class="label">Description</label>
                            <textarea id="product-description" wire:model="description" rows="4"
                                      placeholder="Describe your product…"
                                      class="input resize-none"></textarea>
                        </div>
                    </div>
                </div>

                {{-- Pricing --}}
                <div class="pt-6 border-t border-gray-100 dark:border-gray-700">
                    <h2 class="text-sm font-semibold text-gray-900 dark:text-white mb-5 flex items-center gap-2">
                        <span class="w-1 h-4 bg-emerald-500 rounded-full"></span>
                        Pricing
                    </h2>
                    <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                        <div>
                            <label for="product-price" class="label">Sale Price <span class="text-red-500" aria-hidden="true">*</span></label>
                            <div class="relative">
                                <input id="product-price" type="number" wire:model="price"
                                       step="0.01" min="0" placeholder="0.00"
                                       class="input pr-16 tabular-nums">
                                <span class="absolute right-3 top-1/2 -translate-y-1/2 text-xs font-medium text-gray-400 pointer-events-none">MAD</span>
                            </div>
                            @error('price') <p class="error-text">{{ $message }}</p> @enderror
                        </div>
                        <div>
                            <label for="product-cost" class="label">Cost Price <span class="text-red-500" aria-hidden="true">*</span></label>
                            <div class="relative">
                                <input id="product-cost" type="number" wire:model="cost"
                                       step="0.01" min="0" placeholder="0.00"
                                       class="input pr-16 tabular-nums">
                                <span class="absolute right-3 top-1/2 -translate-y-1/2 text-xs font-medium text-gray-400 pointer-events-none">MAD</span>
                            </div>
                            @error('cost') <p class="error-text">{{ $message }}</p> @enderror
                        </div>
                    </div>
                </div>

                {{-- Status --}}
                <div class="pt-6 border-t border-gray-100 dark:border-gray-700">
                    <h2 class="text-sm font-semibold text-gray-900 dark:text-white mb-5 flex items-center gap-2">
                        <span class="w-1 h-4 bg-amber-500 rounded-full"></span>
                        Status
                    </h2>
                    <div class="max-w-xs">
                        <label for="product-status" class="label">Visibility</label>
                        <select id="product-status" wire:model="status" class="input">
                            <option value="draft">Draft — hidden from customers</option>
                            <option value="active">Active — visible to customers</option>
                            <option value="archived">Archived — removed from listings</option>
                        </select>
                    </div>
                </div>

                {{-- Platform Sync --}}
                <div class="pt-6 border-t border-gray-100 dark:border-gray-700">
                    <h2 class="text-sm font-semibold text-gray-900 dark:text-white mb-5 flex items-center gap-2">
                        <span class="w-1 h-4 bg-violet-500 rounded-full"></span>
                        Platform Sync
                    </h2>

                    @if($platformConnections->isEmpty())
                        <div class="flex items-start gap-3 p-4 bg-gray-50 dark:bg-gray-700/50 border border-gray-200 dark:border-gray-600 rounded-xl">
                            <svg class="w-5 h-5 text-gray-400 flex-shrink-0 mt-0.5" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="1.7" aria-hidden="true">
                                <path stroke-linecap="round" stroke-linejoin="round" d="M8.111 16.404a5.5 5.5 0 017.778 0M12 20h.01m-7.08-7.071c3.904-3.905 10.236-3.905 14.141 0M1.394 9.393c5.857-5.857 15.355-5.857 21.213 0"/>
                            </svg>
                            <div>
                                <p class="text-sm font-medium text-gray-700 dark:text-gray-300">No active platform connections</p>
                                <p class="text-xs text-gray-500 dark:text-gray-400 mt-0.5">Connect a platform in store settings to enable sync.</p>
                            </div>
                        </div>
                    @else
                        @if(empty($product->external_id))
                            <div class="flex items-center gap-3 p-3.5 bg-amber-50 dark:bg-amber-500/10 border border-amber-200 dark:border-amber-500/20 rounded-xl mb-4">
                                <svg class="w-5 h-5 text-amber-500 flex-shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="1.7" aria-hidden="true">
                                    <path stroke-linecap="round" stroke-linejoin="round" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-3L13.732 4c-.77-1.333-2.694-1.333-3.464 0L3.34 16c-.77 1.333.192 3 1.732 3z"/>
                                </svg>
                                <p class="text-sm text-amber-800 dark:text-amber-300">
                                    Not yet pushed to any platform — click <strong>Push Now</strong> to create it on the platform.
                                </p>
                            </div>
                        @endif

                        <div class="space-y-2.5">
                            @foreach($platformConnections as $connection)
                                @php
                                    $lastLog = $lastPushLogs[$connection->id] ?? null;
                                    $badgeClass = match($connection->platform) {
                                        'woocommerce' => 'badge-purple',
                                        'shopify'     => 'badge-green',
                                        default       => 'badge-blue',
                                    };
                                @endphp
                                <div class="flex items-center justify-between p-4 bg-gray-50 dark:bg-gray-700/50 border border-gray-200 dark:border-gray-600 rounded-xl">
                                    <div class="flex items-center gap-3 min-w-0">
                                        <span class="badge {{ $badgeClass }} flex-shrink-0">
                                            {{ ucfirst($connection->platform) }}
                                        </span>
                                        <div class="min-w-0">
                                            <p class="text-sm font-medium text-gray-800 dark:text-gray-200 truncate">
                                                {{ $connection->label ?? $connection->platform }}
                                            </p>
                                            @if(!empty($product->external_id))
                                                <p class="text-xs text-gray-400 dark:text-gray-500 font-mono">ID: {{ $product->external_id }}</p>
                                            @else
                                                <p class="text-xs text-gray-400 dark:text-gray-500">Not yet created on this platform</p>
                                            @endif
                                        </div>
                                    </div>
                                    <div class="flex items-center gap-3 flex-shrink-0">
                                        @if($lastLog)
                                            <div class="text-right hidden sm:block">
                                                @if($lastLog->status === 'success')
                                                    <span class="inline-flex items-center gap-1 text-xs text-emerald-600 dark:text-emerald-400">
                                                        <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="2" aria-hidden="true">
                                                            <path stroke-linecap="round" stroke-linejoin="round" d="M5 13l4 4L19 7"/>
                                                        </svg>
                                                        Synced {{ $lastLog->completed_at?->diffForHumans() }}
                                                    </span>
                                                @else
                                                    <span class="inline-flex items-center gap-1 text-xs text-red-500">
                                                        <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="2" aria-hidden="true">
                                                            <path stroke-linecap="round" stroke-linejoin="round" d="M6 18L18 6M6 6l12 12"/>
                                                        </svg>
                                                        Failed {{ $lastLog->completed_at?->diffForHumans() }}
                                                    </span>
                                                @endif
                                            </div>
                                        @else
                                            <span class="text-xs text-gray-400 dark:text-gray-500 hidden sm:block">Never pushed</span>
                                        @endif
                                        <button wire:click="pushToPlatform('{{ $connection->id }}')"
                                                wire:loading.attr="disabled"
                                                wire:loading.class="opacity-60 cursor-not-allowed"
                                                wire:target="pushToPlatform('{{ $connection->id }}')"
                                                class="inline-flex items-center gap-1.5 px-3 py-1.5 text-xs font-medium
                                                       text-violet-700 dark:text-violet-400 border border-violet-200 dark:border-violet-500/30
                                                       bg-violet-50 dark:bg-violet-500/10 rounded-lg
                                                       hover:bg-violet-600 hover:text-white dark:hover:bg-violet-600 dark:hover:text-white
                                                       transition-colors">
                                            <span wire:loading.remove wire:target="pushToPlatform('{{ $connection->id }}')">
                                                <svg class="w-3.5 h-3.5 inline -mt-0.5 mr-0.5" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="1.7" aria-hidden="true">
                                                    <path stroke-linecap="round" stroke-linejoin="round" d="M4 16v1a3 3 0 003 3h10a3 3 0 003-3v-1m-4-8l-4-4m0 0L8 8m4-4v12"/>
                                                </svg>
                                                {{ empty($product->external_id) ? 'Push Now' : 'Re-push' }}
                                            </span>
                                            <span wire:loading wire:target="pushToPlatform('{{ $connection->id }}')">Pushing…</span>
                                        </button>
                                    </div>
                                </div>
                            @endforeach

                            @if($platformConnections->count() > 1)
                                <div class="flex justify-end pt-1">
                                    <button wire:click="pushToAllPlatforms"
                                            wire:loading.attr="disabled"
                                            wire:loading.class="opacity-60 cursor-not-allowed"
                                            wire:target="pushToAllPlatforms"
                                            class="btn-primary">
                                        <span wire:loading.remove wire:target="pushToAllPlatforms">
                                            <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="1.7" aria-hidden="true">
                                                <path stroke-linecap="round" stroke-linejoin="round" d="M4 16v1a3 3 0 003 3h10a3 3 0 003-3v-1m-4-8l-4-4m0 0L8 8m4-4v12"/>
                                            </svg>
                                            {{ empty($product->external_id) ? 'Push to All Platforms' : 'Re-push to All' }}
                                        </span>
                                        <span wire:loading wire:target="pushToAllPlatforms">Pushing…</span>
                                    </button>
                                </div>
                            @endif
                        </div>
                    @endif
                </div>

                {{-- Form Actions --}}
                <div class="flex items-center justify-end gap-3 pt-6 border-t border-gray-100 dark:border-gray-700">
                    <a href="{{ route('stores.products.index', $store) }}" class="btn-secondary">
                        Cancel
                    </a>
                    <button type="submit" class="btn-primary">
                        <span wire:loading.remove wire:target="save">
                            <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="1.7" aria-hidden="true">
                                <path stroke-linecap="round" stroke-linejoin="round" d="M5 13l4 4L19 7"/>
                            </svg>
                            Save Changes
                        </span>
                        <span wire:loading wire:target="save" class="flex items-center gap-2">
                            <svg class="animate-spin w-4 h-4" fill="none" viewBox="0 0 24 24" aria-hidden="true">
                                <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"/>
                                <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4z"/>
                            </svg>
                            Saving…
                        </span>
                    </button>
                </div>

            </form>
        </div>

        {{-- Variants Tab --}}
        @if($product->isVariable())
            <div x-show="tab === 'variants'"
                 x-transition:enter="transition-opacity duration-150"
                 x-transition:enter-start="opacity-0"
                 x-transition:enter-end="opacity-100"
                 class="p-8">
                <livewire:products.product-variants :product="$product" :key="'variants-' . $product->id" />
            </div>
        @endif

        {{-- Stock Tab --}}
        <div x-show="tab === 'stock'"
             x-transition:enter="transition-opacity duration-150"
             x-transition:enter-start="opacity-0"
             x-transition:enter-end="opacity-100"
             class="p-8">
            <livewire:products.product-stock :product="$product" :key="'stock-' . $product->id" />
        </div>

    </div>
</div>
