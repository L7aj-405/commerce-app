<div class="space-y-6" x-data>

    {{-- Page Header --}}
    <div class="flex items-center gap-3">
        <a href="{{ route('stores.products.index', $store) }}" wire:navigate
            class="btn-icon text-gray-400 hover:text-gray-600 dark:hover:text-gray-200"
            aria-label="Back to products">
            <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="1.7" aria-hidden="true">
                <path stroke-linecap="round" stroke-linejoin="round" d="M10 19l-7-7m0 0l7-7m-7 7h18"/>
            </svg>
        </a>
        <div>
            <h1 class="page-title">
                {{ $name ?: 'New Product' }}
            </h1>
            <p class="page-subtitle">{{ $store->name }}</p>
        </div>
        <div class="ml-auto flex items-center gap-2">
            {{-- Save status indicator --}}
            <span class="flex items-center gap-1.5 text-xs
                @if($saveStatus === 'saving') text-amber-500
                @elseif($saveStatus === 'saved') text-emerald-500
                @elseif($saveStatus === 'error') text-red-500
                @else text-gray-400 @endif">
                @if($saveStatus === 'saving')
                    <span class="w-2 h-2 rounded-full bg-amber-400 animate-pulse"></span>
                    Saving…
                @elseif($saveStatus === 'saved')
                    <span class="w-2 h-2 rounded-full bg-emerald-400"></span>
                    Saved {{ $lastSavedAt }}
                @elseif($saveStatus === 'error')
                    <span class="w-2 h-2 rounded-full bg-red-400"></span>
                    Save failed
                @else
                    <span class="w-2 h-2 rounded-full bg-gray-300 dark:bg-gray-600"></span>
                    Draft
                @endif
            </span>
        </div>
    </div>

    {{-- Step Indicator --}}
    <div class="card py-5 px-6">
        <div class="flex items-start">
            @foreach($stepLabels as $i => $label)
                @php $num = $i + 1; @endphp

                {{-- Step circle + label --}}
                <button
                    @if($num < $step) wire:click="goToStep({{ $num }})" @endif
                    class="flex flex-col items-center gap-1.5 flex-shrink-0 focus:outline-none {{ $num < $step ? 'cursor-pointer' : 'cursor-default' }}"
                    aria-label="Step {{ $num }}: {{ $label }}">
                    <div class="w-9 h-9 rounded-full flex items-center justify-center text-sm font-semibold transition-all
                        @if($num < $step) bg-blue-600 dark:bg-blue-500 text-white
                        @elseif($num === $step) bg-blue-600 dark:bg-blue-500 text-white ring-4 ring-blue-100 dark:ring-blue-500/20
                        @else bg-gray-100 dark:bg-gray-700 text-gray-400 dark:text-gray-500 @endif">
                        @if($num < $step)
                            <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="2.5" aria-hidden="true">
                                <path stroke-linecap="round" stroke-linejoin="round" d="M5 13l4 4L19 7"/>
                            </svg>
                        @else
                            {{ $num }}
                        @endif
                    </div>
                    <span class="text-xs hidden sm:block font-medium
                        {{ $num === $step ? 'text-blue-600 dark:text-blue-400' : ($num < $step ? 'text-gray-600 dark:text-gray-400' : 'text-gray-400 dark:text-gray-500') }}">
                        {{ $label }}
                    </span>
                </button>

                {{-- Connector line --}}
                @if(!$loop->last)
                    <div class="flex-1 h-0.5 mt-4 mx-2 transition-colors
                        {{ $num < $step ? 'bg-blue-600 dark:bg-blue-500' : 'bg-gray-200 dark:bg-gray-700' }}">
                    </div>
                @endif
            @endforeach
        </div>

        {{-- Mobile: step text --}}
        <p class="sm:hidden mt-3 text-xs text-center text-gray-500 dark:text-gray-400">
            Step {{ $step }} of {{ $maxSteps }} — {{ $stepLabels[$step - 1] }}
        </p>
    </div>

    {{-- Step Content Card --}}
    <div class="card space-y-6">

        {{-- ══════════════════════════════════════════ --}}
        {{-- STEP 1: Product Type                      --}}
        {{-- ══════════════════════════════════════════ --}}
        @if($step === 1)
            <div>
                <h2 class="text-lg font-semibold text-gray-900 dark:text-white">What type of product?</h2>
                <p class="text-sm text-gray-500 dark:text-gray-400 mt-1">Choose the right type for your product.</p>
            </div>

            <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                {{-- Simple --}}
                <label class="relative flex cursor-pointer rounded-2xl border-2 p-5 transition-all
                    {{ $productType === 'simple'
                        ? 'border-blue-500 bg-blue-50 dark:bg-blue-500/10'
                        : 'border-gray-200 dark:border-gray-700 hover:border-gray-300 dark:hover:border-gray-600' }}">
                    <input type="radio" wire:model.live="productType" value="simple" class="sr-only">
                    <div class="flex items-start gap-4 flex-1">
                        <div class="w-12 h-12 rounded-xl flex items-center justify-center flex-shrink-0
                            {{ $productType === 'simple' ? 'bg-blue-100 dark:bg-blue-500/20' : 'bg-gray-100 dark:bg-gray-700' }}">
                            <svg class="w-6 h-6 {{ $productType === 'simple' ? 'text-blue-600 dark:text-blue-400' : 'text-gray-400' }}"
                                fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="1.7" aria-hidden="true">
                                <path stroke-linecap="round" stroke-linejoin="round" d="M20 7l-8-4-8 4m16 0l-8 4m8-4v10l-8 4m0-10L4 7m8 4v10M4 7v10l8 4"/>
                            </svg>
                        </div>
                        <div>
                            <p class="font-semibold text-gray-900 dark:text-white">Simple Product</p>
                            <p class="text-sm text-gray-500 dark:text-gray-400 mt-0.5">Single item with one price. No variants needed.</p>
                        </div>
                    </div>
                    @if($productType === 'simple')
                        <svg class="w-5 h-5 text-blue-600 dark:text-blue-400 flex-shrink-0" viewBox="0 0 20 20" fill="currentColor" aria-hidden="true">
                            <path fill-rule="evenodd" d="M10 18a8 8 0 100-16 8 8 0 000 16zm3.707-9.293a1 1 0 00-1.414-1.414L9 10.586 7.707 9.293a1 1 0 00-1.414 1.414l2 2a1 1 0 001.414 0l4-4z" clip-rule="evenodd"/>
                        </svg>
                    @endif
                </label>

                {{-- Variable --}}
                <label class="relative flex cursor-pointer rounded-2xl border-2 p-5 transition-all
                    {{ $productType === 'variable'
                        ? 'border-blue-500 bg-blue-50 dark:bg-blue-500/10'
                        : 'border-gray-200 dark:border-gray-700 hover:border-gray-300 dark:hover:border-gray-600' }}">
                    <input type="radio" wire:model.live="productType" value="variable" class="sr-only">
                    <div class="flex items-start gap-4 flex-1">
                        <div class="w-12 h-12 rounded-xl flex items-center justify-center flex-shrink-0
                            {{ $productType === 'variable' ? 'bg-blue-100 dark:bg-blue-500/20' : 'bg-gray-100 dark:bg-gray-700' }}">
                            <svg class="w-6 h-6 {{ $productType === 'variable' ? 'text-blue-600 dark:text-blue-400' : 'text-gray-400' }}"
                                fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="1.7" aria-hidden="true">
                                <path stroke-linecap="round" stroke-linejoin="round" d="M7 7h.01M7 3h5c.512 0 1.024.195 1.414.586l7 7a2 2 0 010 2.828l-7 7a2 2 0 01-2.828 0l-7-7A1.994 1.994 0 013 12V7a4 4 0 014-4z"/>
                            </svg>
                        </div>
                        <div>
                            <p class="font-semibold text-gray-900 dark:text-white">Variable Product</p>
                            <p class="text-sm text-gray-500 dark:text-gray-400 mt-0.5">Multiple options like size, color, or material.</p>
                        </div>
                    </div>
                    @if($productType === 'variable')
                        <svg class="w-5 h-5 text-blue-600 dark:text-blue-400 flex-shrink-0" viewBox="0 0 20 20" fill="currentColor" aria-hidden="true">
                            <path fill-rule="evenodd" d="M10 18a8 8 0 100-16 8 8 0 000 16zm3.707-9.293a1 1 0 00-1.414-1.414L9 10.586 7.707 9.293a1 1 0 00-1.414 1.414l2 2a1 1 0 001.414 0l4-4z" clip-rule="evenodd"/>
                        </svg>
                    @endif
                </label>
            </div>
        @endif

        {{-- ══════════════════════════════════════════ --}}
        {{-- STEP 2: Basic Info                        --}}
        {{-- ══════════════════════════════════════════ --}}
        @if($step === 2)
            <div>
                <h2 class="text-lg font-semibold text-gray-900 dark:text-white">Basic Information</h2>
                <p class="text-sm text-gray-500 dark:text-gray-400 mt-1">Core details about your product.</p>
            </div>

            {{-- Product Name --}}
            <div>
                <label for="product-name" class="label">Product Name <span class="text-red-500">*</span></label>
                <input id="product-name" type="text"
                    wire:model.live.debounce.500ms="name"
                    placeholder="e.g. Classic White T-Shirt"
                    class="input mt-1.5 @error('name') border-red-300 dark:border-red-500 @enderror"
                    autocomplete="off">
                @error('name') <p class="error-text mt-1">{{ $message }}</p> @enderror
            </div>

            {{-- SKU --}}
            <div>
                <div class="flex items-center justify-between mb-1.5">
                    <label for="product-sku" class="label mb-0">SKU <span class="text-red-500">*</span></label>
                    <button type="button" wire:click="generateSku"
                        class="text-xs text-blue-600 dark:text-blue-400 hover:underline font-medium">
                        Generate
                    </button>
                </div>
                <input id="product-sku" type="text"
                    wire:model.live.debounce.500ms="sku"
                    placeholder="e.g. TS-WHT-001"
                    class="input @error('sku') border-red-300 dark:border-red-500 @enderror"
                    autocomplete="off">
                @error('sku') <p class="error-text mt-1">{{ $message }}</p> @enderror
                <p class="mt-1.5 text-xs text-gray-500 dark:text-gray-400">Unique identifier for this product. Used for inventory tracking.</p>
            </div>

            {{-- Description --}}
            <div>
                <label for="product-desc" class="label">Description</label>
                <textarea id="product-desc"
                    wire:model.live.debounce.800ms="description"
                    rows="4"
                    placeholder="Describe your product…"
                    class="input mt-1.5 resize-none"></textarea>
            </div>
        @endif

        {{-- ══════════════════════════════════════════ --}}
        {{-- STEP 3 — SIMPLE: Pricing                  --}}
        {{-- ══════════════════════════════════════════ --}}
        @if($step === 3 && $productType === 'simple')
            <div>
                <h2 class="text-lg font-semibold text-gray-900 dark:text-white">Pricing</h2>
                <p class="text-sm text-gray-500 dark:text-gray-400 mt-1">Set your sale price and cost to calculate profit.</p>
            </div>

            <div class="grid grid-cols-1 sm:grid-cols-3 gap-5">
                {{-- Sale Price --}}
                <div>
                    <label for="price" class="label">Sale Price <span class="text-red-500">*</span></label>
                    <div class="relative mt-1.5">
                        <span class="absolute left-3.5 top-1/2 -translate-y-1/2 text-sm text-gray-400 font-medium pointer-events-none">{{ $currency }}</span>
                        <input id="price" type="number" step="0.01" min="0"
                            wire:model.live.debounce.400ms="price"
                            placeholder="0.00"
                            class="input pl-14 tabular-nums @error('price') border-red-300 dark:border-red-500 @enderror">
                    </div>
                    @error('price') <p class="error-text mt-1">{{ $message }}</p> @enderror
                </div>

                {{-- Cost --}}
                <div>
                    <label for="cost" class="label">Cost Price <span class="text-red-500">*</span></label>
                    <div class="relative mt-1.5">
                        <span class="absolute left-3.5 top-1/2 -translate-y-1/2 text-sm text-gray-400 font-medium pointer-events-none">{{ $currency }}</span>
                        <input id="cost" type="number" step="0.01" min="0"
                            wire:model.live.debounce.400ms="cost"
                            placeholder="0.00"
                            class="input pl-14 tabular-nums @error('cost') border-red-300 dark:border-red-500 @enderror">
                    </div>
                    @error('cost') <p class="error-text mt-1">{{ $message }}</p> @enderror
                </div>

                {{-- Compare Price --}}
                <div>
                    <label for="compare-price" class="label">Compare At</label>
                    <div class="relative mt-1.5">
                        <span class="absolute left-3.5 top-1/2 -translate-y-1/2 text-sm text-gray-400 font-medium pointer-events-none">{{ $currency }}</span>
                        <input id="compare-price" type="number" step="0.01" min="0"
                            wire:model.live.debounce.400ms="comparePrice"
                            placeholder="0.00"
                            class="input pl-14 tabular-nums">
                    </div>
                    <p class="mt-1.5 text-xs text-gray-500 dark:text-gray-400">Original price (shows crossed-out).</p>
                </div>
            </div>

            {{-- Profit calculator --}}
            @if((float)$price > 0)
                <div class="rounded-xl p-4 {{ $profit >= 0 ? 'bg-emerald-50 dark:bg-emerald-500/10 border border-emerald-200 dark:border-emerald-500/20' : 'bg-red-50 dark:bg-red-500/10 border border-red-200 dark:border-red-500/20' }}">
                    <div class="flex items-center gap-3">
                        <svg class="w-5 h-5 {{ $profit >= 0 ? 'text-emerald-600 dark:text-emerald-400' : 'text-red-500' }}"
                            fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="1.7" aria-hidden="true">
                            @if($profit >= 0)
                                <path stroke-linecap="round" stroke-linejoin="round" d="M13 7h8m0 0v8m0-8l-8 8-4-4-6 6"/>
                            @else
                                <path stroke-linecap="round" stroke-linejoin="round" d="M13 17H5m0 0V9m0 8l8-8 4 4 6-6"/>
                            @endif
                        </svg>
                        <div>
                            <p class="text-sm font-semibold {{ $profit >= 0 ? 'text-emerald-800 dark:text-emerald-300' : 'text-red-700 dark:text-red-400' }}">
                                Profit: {{ number_format($profit, 2) }} {{ $currency }}
                                @if($profitMargin > 0)
                                    <span class="font-normal">({{ $profitMargin }}% margin)</span>
                                @endif
                            </p>
                            <p class="text-xs {{ $profit >= 0 ? 'text-emerald-600 dark:text-emerald-400' : 'text-red-500' }} mt-0.5">
                                {{ number_format((float)$price, 2) }} price − {{ number_format((float)$cost, 2) }} cost = {{ number_format($profit, 2) }} {{ $currency }}
                            </p>
                        </div>
                    </div>
                </div>
            @endif
        @endif

        {{-- ══════════════════════════════════════════ --}}
        {{-- STEP 3 — VARIABLE: Attributes             --}}
        {{-- ══════════════════════════════════════════ --}}
        @if($step === 3 && $productType === 'variable')
            <div>
                <h2 class="text-lg font-semibold text-gray-900 dark:text-white">Attributes</h2>
                <p class="text-sm text-gray-500 dark:text-gray-400 mt-1">Define what varies (e.g. Size, Color). Variants are generated automatically.</p>
            </div>

            {{-- Existing attributes --}}
            @if(!empty($productAttributes))
                <div class="space-y-3">
                    @foreach($productAttributes as $attrIndex => $attr)
                        <div class="border border-gray-200 dark:border-gray-700 rounded-xl p-4">
                            <div class="flex items-center justify-between mb-3">
                                <span class="text-sm font-semibold text-gray-900 dark:text-white">{{ $attr['name'] }}</span>
                                <button wire:click="removeAttribute({{ $attrIndex }})"
                                    class="btn-icon text-gray-400 hover:text-red-500 hover:bg-red-50 dark:hover:bg-red-500/10 dark:hover:text-red-400"
                                    aria-label="Remove {{ $attr['name'] }}">
                                    <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="1.7" aria-hidden="true">
                                        <path stroke-linecap="round" stroke-linejoin="round" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16"/>
                                    </svg>
                                    <span class="sr-only">Remove {{ $attr['name'] }}</span>
                                </button>
                            </div>

                            {{-- Values --}}
                            <div class="flex flex-wrap gap-1.5 mb-3">
                                @forelse($attr['values'] as $valueIndex => $value)
                                    <span class="inline-flex items-center gap-1 px-2.5 py-1 rounded-lg text-xs font-medium
                                        bg-blue-50 dark:bg-blue-500/10 text-blue-700 dark:text-blue-300
                                        border border-blue-100 dark:border-blue-500/20">
                                        {{ $value }}
                                        <button wire:click="removeAttributeValue({{ $attrIndex }}, {{ $valueIndex }})"
                                            class="text-blue-400 hover:text-red-500 dark:hover:text-red-400 ml-0.5"
                                            aria-label="Remove {{ $value }}">
                                            <svg class="w-3 h-3" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="2.5" aria-hidden="true">
                                                <path stroke-linecap="round" stroke-linejoin="round" d="M6 18L18 6M6 6l12 12"/>
                                            </svg>
                                        </button>
                                    </span>
                                @empty
                                    <span class="text-xs text-gray-400 dark:text-gray-500 italic">No values yet</span>
                                @endforelse
                            </div>

                            @error("attr_{$attrIndex}_value")
                                <p class="error-text mb-2">{{ $message }}</p>
                            @enderror

                            {{-- Add value --}}
                            <div class="flex gap-2">
                                <input type="text"
                                    wire:model.defer="newAttrValueInputs.{{ $attrIndex }}"
                                    wire:keydown.enter="addAttributeValue({{ $attrIndex }})"
                                    placeholder="Add value…"
                                    class="input flex-1 py-1.5 text-sm">
                                <button wire:click="addAttributeValue({{ $attrIndex }})"
                                    class="px-3 py-1.5 text-xs font-medium text-white bg-blue-600 hover:bg-blue-700 dark:bg-blue-500 dark:hover:bg-blue-600 rounded-lg transition-colors">
                                    Add
                                </button>
                            </div>
                        </div>
                    @endforeach
                </div>
            @endif

            {{-- Add new attribute --}}
            <div class="border-t border-gray-100 dark:border-gray-700 pt-4">
                <p class="text-xs font-semibold text-gray-500 dark:text-gray-400 uppercase tracking-wide mb-2">
                    @if(empty($productAttributes)) Add Your First Attribute @else Add Another Attribute @endif
                </p>
                <div class="flex gap-2">
                    <input type="text"
                        wire:model.defer="newAttrName"
                        wire:keydown.enter="addAttribute"
                        placeholder="e.g. Color, Size, Material…"
                        class="input flex-1">
                    <button wire:click="addAttribute" class="btn btn-primary flex-shrink-0">
                        <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="1.7" aria-hidden="true">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M12 4v16m8-8H4"/>
                        </svg>
                        Add
                    </button>
                </div>
                @error('newAttrName') <p class="error-text mt-1">{{ $message }}</p> @enderror
            </div>

            {{-- Combinations preview --}}
            @if(!empty($variants))
                <div class="flex items-center gap-2 rounded-xl bg-indigo-50 dark:bg-indigo-500/10 border border-indigo-200 dark:border-indigo-500/20 px-4 py-3">
                    <svg class="w-4 h-4 text-indigo-500 dark:text-indigo-400 flex-shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="1.7" aria-hidden="true">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M9 5H7a2 2 0 00-2 2v12a2 2 0 002 2h10a2 2 0 002-2V7a2 2 0 00-2-2h-2M9 5a2 2 0 002 2h2a2 2 0 002-2M9 5a2 2 0 012-2h2a2 2 0 012 2"/>
                    </svg>
                    <p class="text-sm font-medium text-indigo-800 dark:text-indigo-300">
                        {{ count($variants) }} {{ Str::plural('variant', count($variants)) }} will be generated from
                        {{ collect($productAttributes)->pluck('name')->join(', ') }}
                    </p>
                </div>
            @endif
        @endif

        {{-- ══════════════════════════════════════════ --}}
        {{-- STEP 4 — SIMPLE: Stock                    --}}
        {{-- ══════════════════════════════════════════ --}}
        @if($step === 4 && $productType === 'simple')
            <div>
                <h2 class="text-lg font-semibold text-gray-900 dark:text-white">Initial Stock</h2>
                <p class="text-sm text-gray-500 dark:text-gray-400 mt-1">Set the starting inventory for this product.</p>
            </div>

            <div class="grid grid-cols-1 sm:grid-cols-2 gap-5">
                <div>
                    <label for="warehouse" class="label">Warehouse</label>
                    <select id="warehouse" wire:model.live="warehouseId" class="input mt-1.5">
                        <option value="">No warehouse</option>
                        @foreach($warehouses as $wh)
                            <option value="{{ $wh->id }}">{{ $wh->name }}</option>
                        @endforeach
                    </select>
                    @if($warehouses->isEmpty())
                        <p class="mt-1.5 text-xs text-amber-600 dark:text-amber-400">
                            No warehouses found.
                            <a href="{{ route('warehouses.create') }}" wire:navigate class="underline">Create one</a>.
                        </p>
                    @endif
                </div>

                <div>
                    <label for="quantity" class="label">Quantity</label>
                    <input id="quantity" type="number" min="0"
                        wire:model.live="quantity"
                        placeholder="0"
                        class="input mt-1.5 tabular-nums">
                    <p class="mt-1.5 text-xs text-gray-500 dark:text-gray-400">Enter 0 to start with no stock.</p>
                </div>
            </div>

            {{-- Live preview --}}
            @if($warehouseId && $quantity > 0)
                <div class="flex items-center gap-3 rounded-xl bg-emerald-50 dark:bg-emerald-500/10 border border-emerald-200 dark:border-emerald-500/20 px-4 py-3">
                    <svg class="w-4 h-4 text-emerald-500 flex-shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="1.7" aria-hidden="true">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M20 7l-8-4-8 4m16 0l-8 4m8-4v10l-8 4m0-10L4 7m8 4v10M4 7v10l8 4"/>
                    </svg>
                    <p class="text-sm font-medium text-emerald-800 dark:text-emerald-300">
                        {{ $quantity }} {{ Str::plural('unit', $quantity) }} in
                        {{ $warehouses->firstWhere('id', $warehouseId)?->name ?? 'selected warehouse' }}
                    </p>
                </div>
            @endif
        @endif

        {{-- ══════════════════════════════════════════ --}}
        {{-- STEP 4 — VARIABLE: Variants               --}}
        {{-- ══════════════════════════════════════════ --}}
        @if($step === 4 && $productType === 'variable')
            <div class="flex items-center justify-between">
                <div>
                    <h2 class="text-lg font-semibold text-gray-900 dark:text-white">Generated Variants</h2>
                    <p class="text-sm text-gray-500 dark:text-gray-400 mt-1">
                        {{ $totalSelectedVariants }} of {{ count($variants) }} variants selected.
                        Uncheck any you don't want to create.
                    </p>
                </div>
                <span class="badge badge-indigo">{{ count($variants) }} total</span>
            </div>

            @error('variants') <p class="error-text">{{ $message }}</p> @enderror

            @if(!empty($variants))
                <div class="overflow-x-auto -mx-6">
                    <table class="w-full" aria-label="Generated variants">
                        <thead>
                            <tr class="border-b border-gray-100 dark:border-gray-700">
                                <th scope="col" class="px-6 py-3 text-left text-xs font-semibold text-gray-500 dark:text-gray-400 uppercase tracking-wider w-10"></th>
                                <th scope="col" class="px-6 py-3 text-left text-xs font-semibold text-gray-500 dark:text-gray-400 uppercase tracking-wider">Variant</th>
                                <th scope="col" class="px-6 py-3 text-left text-xs font-semibold text-gray-500 dark:text-gray-400 uppercase tracking-wider">SKU</th>
                                <th scope="col" class="px-6 py-3 text-left text-xs font-semibold text-gray-500 dark:text-gray-400 uppercase tracking-wider">Attributes</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-gray-100 dark:divide-gray-700">
                            @foreach($variants as $index => $variant)
                                <tr class="{{ !($variant['selected'] ?? true) ? 'opacity-40' : '' }} transition-opacity">
                                    <td class="px-6 py-3">
                                        <input type="checkbox"
                                            wire:model.live="variants.{{ $index }}.selected"
                                            class="w-4 h-4 text-blue-600 border-gray-300 dark:border-gray-600 rounded focus:ring-blue-500 dark:bg-gray-700"
                                            aria-label="Include {{ $variant['name'] }}">
                                    </td>
                                    <td class="px-6 py-3">
                                        <span class="text-sm font-medium text-gray-900 dark:text-white">{{ $variant['name'] }}</span>
                                    </td>
                                    <td class="px-6 py-3">
                                        <input type="text"
                                            wire:model.defer="variants.{{ $index }}.sku"
                                            placeholder="SKU"
                                            class="input py-1.5 text-xs w-36 font-mono"
                                            aria-label="SKU for {{ $variant['name'] }}">
                                    </td>
                                    <td class="px-6 py-3">
                                        <div class="flex flex-wrap gap-1">
                                            @foreach($variant['attributes'] as $attrName => $attrValue)
                                                <span class="inline-flex items-center px-2 py-0.5 rounded text-xs bg-gray-100 dark:bg-gray-700 text-gray-600 dark:text-gray-300 font-medium">
                                                    {{ $attrName }}: {{ $attrValue }}
                                                </span>
                                            @endforeach
                                        </div>
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            @endif
        @endif

        {{-- ══════════════════════════════════════════ --}}
        {{-- STEP 5 — VARIABLE: Pricing & Stock        --}}
        {{-- ══════════════════════════════════════════ --}}
        @if($step === 5 && $productType === 'variable')
            <div>
                <h2 class="text-lg font-semibold text-gray-900 dark:text-white">Pricing & Stock</h2>
                <p class="text-sm text-gray-500 dark:text-gray-400 mt-1">Set price, cost, and initial quantity per variant.</p>
            </div>

            {{-- Bulk controls --}}
            <div class="bg-gray-50 dark:bg-gray-700/50 rounded-xl p-4 space-y-4">
                <p class="text-xs font-semibold text-gray-500 dark:text-gray-400 uppercase tracking-wide">Apply to All Variants</p>
                <div class="grid grid-cols-1 sm:grid-cols-3 gap-3">
                    <div>
                        <label class="label text-xs">Base Price</label>
                        <div class="relative mt-1">
                            <span class="absolute left-3 top-1/2 -translate-y-1/2 text-xs text-gray-400 pointer-events-none">{{ $currency }}</span>
                            <input type="number" step="0.01" min="0"
                                wire:model.defer="price"
                                placeholder="0.00"
                                class="input pl-12 py-2 text-sm tabular-nums">
                        </div>
                    </div>
                    <div>
                        <label class="label text-xs">Base Cost</label>
                        <div class="relative mt-1">
                            <span class="absolute left-3 top-1/2 -translate-y-1/2 text-xs text-gray-400 pointer-events-none">{{ $currency }}</span>
                            <input type="number" step="0.01" min="0"
                                wire:model.defer="cost"
                                placeholder="0.00"
                                class="input pl-12 py-2 text-sm tabular-nums">
                        </div>
                    </div>
                    <div>
                        <label class="label text-xs">Base Qty</label>
                        <input type="number" min="0"
                            wire:model.defer="baseQty"
                            placeholder="0"
                            class="input mt-1 py-2 text-sm tabular-nums">
                    </div>
                </div>
                <div class="flex gap-2">
                    <button wire:click="applyBasePriceToAll" class="btn btn-secondary text-xs py-1.5 px-3">
                        Apply Price to All
                    </button>
                    <button wire:click="applyBaseQtyToAll" class="btn btn-secondary text-xs py-1.5 px-3">
                        Apply Qty to All
                    </button>
                </div>
            </div>

            {{-- Warehouse --}}
            <div>
                <label for="variant-warehouse" class="label">Warehouse</label>
                <select id="variant-warehouse" wire:model.live="warehouseId" class="input mt-1.5">
                    <option value="">No warehouse</option>
                    @foreach($warehouses as $wh)
                        <option value="{{ $wh->id }}">{{ $wh->name }}</option>
                    @endforeach
                </select>
            </div>

            {{-- Per-variant table --}}
            <div class="overflow-x-auto -mx-6">
                <table class="w-full" aria-label="Variant pricing and stock">
                    <thead>
                        <tr class="border-b border-gray-100 dark:border-gray-700">
                            <th scope="col" class="px-6 py-3 text-left text-xs font-semibold text-gray-500 dark:text-gray-400 uppercase tracking-wider">Variant</th>
                            <th scope="col" class="px-6 py-3 text-left text-xs font-semibold text-gray-500 dark:text-gray-400 uppercase tracking-wider">Price</th>
                            <th scope="col" class="px-6 py-3 text-left text-xs font-semibold text-gray-500 dark:text-gray-400 uppercase tracking-wider">Cost</th>
                            <th scope="col" class="px-6 py-3 text-left text-xs font-semibold text-gray-500 dark:text-gray-400 uppercase tracking-wider">Qty</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-100 dark:divide-gray-700">
                        @foreach($variants as $index => $variant)
                            @if($variant['selected'] ?? true)
                                <tr class="hover:bg-gray-50/60 dark:hover:bg-gray-700/30 transition-colors">
                                    <td class="px-6 py-3 text-sm font-medium text-gray-900 dark:text-white">
                                        {{ $variant['name'] }}
                                        @if($variant['sku'])
                                            <span class="block text-xs text-gray-400 font-mono mt-0.5">{{ $variant['sku'] }}</span>
                                        @endif
                                    </td>
                                    <td class="px-6 py-3">
                                        <div class="relative">
                                            <span class="absolute left-2.5 top-1/2 -translate-y-1/2 text-xs text-gray-400 pointer-events-none">{{ $currency }}</span>
                                            <input type="number" step="0.01" min="0"
                                                wire:model.defer="variants.{{ $index }}.price"
                                                class="input w-28 pl-10 py-1.5 text-sm tabular-nums"
                                                aria-label="Price for {{ $variant['name'] }}">
                                        </div>
                                    </td>
                                    <td class="px-6 py-3">
                                        <div class="relative">
                                            <span class="absolute left-2.5 top-1/2 -translate-y-1/2 text-xs text-gray-400 pointer-events-none">{{ $currency }}</span>
                                            <input type="number" step="0.01" min="0"
                                                wire:model.defer="variants.{{ $index }}.cost"
                                                class="input w-28 pl-10 py-1.5 text-sm tabular-nums"
                                                aria-label="Cost for {{ $variant['name'] }}">
                                        </div>
                                    </td>
                                    <td class="px-6 py-3">
                                        <input type="number" min="0"
                                            wire:model.defer="variants.{{ $index }}.qty"
                                            class="input w-20 py-1.5 text-sm text-center tabular-nums"
                                            aria-label="Quantity for {{ $variant['name'] }}">
                                    </td>
                                </tr>
                            @endif
                        @endforeach
                    </tbody>
                    <tfoot>
                        <tr class="border-t-2 border-gray-200 dark:border-gray-600">
                            <td colspan="3" class="px-6 py-3 text-xs font-semibold text-right text-gray-500 dark:text-gray-400">Total Stock</td>
                            <td class="px-6 py-3 text-sm font-bold text-gray-900 dark:text-white tabular-nums text-center">
                                {{ $totalVariantStock }}
                            </td>
                        </tr>
                    </tfoot>
                </table>
            </div>
        @endif

        {{-- ══════════════════════════════════════════ --}}
        {{-- REVIEW STEP (step 5 simple / step 6 var.) --}}
        {{-- ══════════════════════════════════════════ --}}
        @if(($step === 5 && $productType === 'simple') || ($step === 6 && $productType === 'variable'))
            <div>
                <h2 class="text-lg font-semibold text-gray-900 dark:text-white">Review Your Product</h2>
                <p class="text-sm text-gray-500 dark:text-gray-400 mt-1">Everything looks good? Save as draft or push to your platforms.</p>
            </div>

            {{-- Summary card --}}
            <div class="bg-gray-50 dark:bg-gray-700/40 rounded-2xl p-5 space-y-4">

                {{-- Header row --}}
                <div class="flex items-start justify-between">
                    <div>
                        <h3 class="font-semibold text-gray-900 dark:text-white text-base">{{ $name }}</h3>
                        <p class="text-xs text-gray-500 dark:text-gray-400 font-mono mt-0.5">SKU: {{ $sku }}</p>
                    </div>
                    <div class="flex items-center gap-2">
                        <span class="badge {{ $productType === 'variable' ? 'badge-indigo' : 'badge-blue' }}">
                            {{ ucfirst($productType) }}
                        </span>
                        <span class="badge badge-yellow">Draft</span>
                    </div>
                </div>

                @if($description)
                    <p class="text-sm text-gray-600 dark:text-gray-400 border-t border-gray-200 dark:border-gray-600 pt-3">
                        {{ Str::limit($description, 120) }}
                    </p>
                @endif

                <div class="divider"></div>

                @if($productType === 'simple')
                    {{-- Simple pricing summary --}}
                    <div class="grid grid-cols-2 sm:grid-cols-4 gap-4">
                        <div>
                            <p class="text-xs text-gray-500 dark:text-gray-400 uppercase tracking-wide font-medium">Price</p>
                            <p class="mt-1 text-base font-bold text-gray-900 dark:text-white tabular-nums">{{ number_format((float)$price, 2) }} {{ $currency }}</p>
                        </div>
                        <div>
                            <p class="text-xs text-gray-500 dark:text-gray-400 uppercase tracking-wide font-medium">Cost</p>
                            <p class="mt-1 text-base font-semibold text-gray-700 dark:text-gray-300 tabular-nums">{{ number_format((float)$cost, 2) }} {{ $currency }}</p>
                        </div>
                        <div>
                            <p class="text-xs text-gray-500 dark:text-gray-400 uppercase tracking-wide font-medium">Profit</p>
                            <p class="mt-1 text-base font-semibold {{ $profit >= 0 ? 'text-emerald-600 dark:text-emerald-400' : 'text-red-500' }} tabular-nums">
                                {{ number_format($profit, 2) }} {{ $currency }}
                            </p>
                        </div>
                        <div>
                            <p class="text-xs text-gray-500 dark:text-gray-400 uppercase tracking-wide font-medium">Stock</p>
                            <p class="mt-1 text-base font-semibold text-gray-900 dark:text-white tabular-nums">{{ $quantity }} units</p>
                        </div>
                    </div>
                @else
                    {{-- Variable summary --}}
                    <div class="grid grid-cols-2 sm:grid-cols-3 gap-4">
                        <div>
                            <p class="text-xs text-gray-500 dark:text-gray-400 uppercase tracking-wide font-medium">Variants</p>
                            <p class="mt-1 text-base font-bold text-gray-900 dark:text-white">{{ $totalSelectedVariants }} included</p>
                        </div>
                        <div>
                            <p class="text-xs text-gray-500 dark:text-gray-400 uppercase tracking-wide font-medium">Price Range</p>
                            <p class="mt-1 text-base font-semibold text-gray-900 dark:text-white tabular-nums">
                                @if($priceRange['min'] === $priceRange['max'])
                                    {{ number_format($priceRange['min'], 2) }} {{ $currency }}
                                @else
                                    {{ number_format($priceRange['min'], 2) }} – {{ number_format($priceRange['max'], 2) }} {{ $currency }}
                                @endif
                            </p>
                        </div>
                        <div>
                            <p class="text-xs text-gray-500 dark:text-gray-400 uppercase tracking-wide font-medium">Total Stock</p>
                            <p class="mt-1 text-base font-semibold text-gray-900 dark:text-white tabular-nums">{{ $totalVariantStock }} units</p>
                        </div>
                    </div>

                    @if(!empty($productAttributes))
                        <div class="border-t border-gray-200 dark:border-gray-600 pt-3">
                            <p class="text-xs text-gray-500 dark:text-gray-400 uppercase tracking-wide font-medium mb-2">Attributes</p>
                            <div class="flex flex-wrap gap-1.5">
                                @foreach($productAttributes as $attr)
                                    <span class="px-2.5 py-1 rounded-lg bg-white dark:bg-gray-700 border border-gray-200 dark:border-gray-600 text-xs text-gray-700 dark:text-gray-300 font-medium">
                                        {{ $attr['name'] }}: {{ implode(', ', $attr['values']) }}
                                    </span>
                                @endforeach
                            </div>
                        </div>
                    @endif
                @endif

            </div>

            {{-- Platform connections info --}}
            @if($connections->isNotEmpty())
                <div>
                    <p class="text-xs font-semibold text-gray-500 dark:text-gray-400 uppercase tracking-wide mb-2">Connected Platforms</p>
                    <div class="flex flex-wrap gap-2">
                        @foreach($connections as $conn)
                            <span class="badge badge-gray">{{ ucfirst($conn->platform) }}</span>
                        @endforeach
                    </div>
                </div>
            @endif
        @endif

        {{-- ══════════════════════════════════════════ --}}
        {{-- Navigation Buttons                        --}}
        {{-- ══════════════════════════════════════════ --}}
        <div class="flex items-center justify-between pt-4 border-t border-gray-100 dark:border-gray-700">

            {{-- Back --}}
            @if($step === 1)
                <a href="{{ route('stores.products.index', $store) }}" wire:navigate class="btn btn-ghost">
                    <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="1.7" aria-hidden="true">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M10 19l-7-7m0 0l7-7m-7 7h18"/>
                    </svg>
                    Cancel
                </a>
            @else
                <button wire:click="prevStep" class="btn btn-secondary">
                    <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="1.7" aria-hidden="true">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M10 19l-7-7m0 0l7-7m-7 7h18"/>
                    </svg>
                    Back
                </button>
            @endif

            {{-- Continue / Final actions --}}
            @php $isReview = ($step === 5 && $productType === 'simple') || ($step === 6 && $productType === 'variable'); @endphp

            @if($isReview)
                <div class="flex items-center gap-2">
                    <button wire:click="saveAsDraft" class="btn btn-secondary">
                        <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="1.7" aria-hidden="true">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M8 7H5a2 2 0 00-2 2v9a2 2 0 002 2h14a2 2 0 002-2V9a2 2 0 00-2-2h-3m-1 4l-3 3m0 0l-3-3m3 3V4"/>
                        </svg>
                        Save Draft
                    </button>
                    @if($connections->isNotEmpty())
                        <button wire:click="openPushModal" class="btn btn-primary">
                            <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="1.7" aria-hidden="true">
                                <path stroke-linecap="round" stroke-linejoin="round" d="M7 16a4 4 0 01-.88-7.903A5 5 0 1115.9 6L16 6a5 5 0 011 9.9M15 13l-3-3m0 0l-3 3m3-3v12"/>
                            </svg>
                            Push to Platforms
                        </button>
                    @endif
                </div>
            @else
                <button wire:click="nextStep" class="btn btn-primary">
                    Continue
                    <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="1.7" aria-hidden="true">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M14 5l7 7m0 0l-7 7m7-7H3"/>
                    </svg>
                </button>
            @endif
        </div>

    </div>{{-- /card --}}


    {{-- ══════════════════════════════════════════════════ --}}
    {{-- PUSH MODAL                                       --}}
    {{-- ══════════════════════════════════════════════════ --}}
    @if($showPushModal)
        <div class="fixed inset-0 z-50 flex items-center justify-center p-4"
            x-data x-init
            x-on:keydown.escape.window="$wire.closePushModal()">

            {{-- Backdrop --}}
            <div class="absolute inset-0 bg-black/60 backdrop-blur-sm"
                @if(!$isPushing && !$pushDone) wire:click="closePushModal" @endif></div>

            {{-- Modal --}}
            <div class="relative w-full max-w-md bg-white dark:bg-gray-800 rounded-2xl shadow-2xl border border-gray-200 dark:border-gray-700 overflow-hidden">

                {{-- Header --}}
                <div class="px-6 py-5 border-b border-gray-100 dark:border-gray-700">
                    <div class="flex items-center gap-3">
                        <div class="w-10 h-10 rounded-xl bg-blue-50 dark:bg-blue-500/20 flex items-center justify-center flex-shrink-0">
                            <svg class="w-5 h-5 text-blue-600 dark:text-blue-400" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="1.7" aria-hidden="true">
                                <path stroke-linecap="round" stroke-linejoin="round" d="M7 16a4 4 0 01-.88-7.903A5 5 0 1115.9 6L16 6a5 5 0 011 9.9M15 13l-3-3m0 0l-3 3m3-3v12"/>
                            </svg>
                        </div>
                        <div>
                            <h3 class="text-base font-semibold text-gray-900 dark:text-white">Push to Platforms</h3>
                            <p class="text-xs text-gray-500 dark:text-gray-400 mt-0.5">Product will be published on selected platforms.</p>
                        </div>
                    </div>
                </div>

                <div class="px-6 py-5 space-y-4">

                    @if(!$isPushing && !$pushDone)
                        {{-- Platform selection --}}
                        <div class="space-y-2">
                            @foreach($connections as $conn)
                                <label class="flex items-center gap-3 p-3 rounded-xl border border-gray-200 dark:border-gray-700 cursor-pointer hover:bg-gray-50 dark:hover:bg-gray-700/50 transition-colors">
                                    <input type="checkbox"
                                        wire:model.live="selectedConnectionIds"
                                        value="{{ $conn->id }}"
                                        class="w-4 h-4 text-blue-600 border-gray-300 dark:border-gray-600 rounded focus:ring-blue-500 dark:bg-gray-700">
                                    <div class="flex items-center gap-2 flex-1">
                                        <span class="text-sm font-medium text-gray-900 dark:text-white">{{ ucfirst($conn->platform) }}</span>
                                        @if($conn->label)
                                            <span class="text-xs text-gray-400 dark:text-gray-500">{{ $conn->label }}</span>
                                        @endif
                                    </div>
                                    <span class="badge badge-green text-xs">Active</span>
                                </label>
                            @endforeach
                        </div>

                        <p class="text-xs text-gray-500 dark:text-gray-400">
                            Status will change from <span class="font-medium text-amber-600 dark:text-amber-400">Draft</span>
                            to <span class="font-medium text-emerald-600 dark:text-emerald-400">Published</span>.
                        </p>

                    @elseif($isPushing)
                        {{-- Loading --}}
                        <div class="flex flex-col items-center py-6 gap-3">
                            <div class="w-10 h-10 border-4 border-blue-200 dark:border-blue-500/30 border-t-blue-600 dark:border-t-blue-400 rounded-full animate-spin"></div>
                            <p class="text-sm font-medium text-gray-700 dark:text-gray-300">Publishing your product…</p>
                            <p class="text-xs text-gray-400 dark:text-gray-500">This may take a few seconds.</p>
                        </div>

                    @else
                        {{-- Results --}}
                        <div class="space-y-2">
                            @foreach($pushResults as $result)
                                <div class="flex items-center gap-3 p-3 rounded-xl
                                    {{ $result['success'] ?? false
                                        ? 'bg-emerald-50 dark:bg-emerald-500/10 border border-emerald-200 dark:border-emerald-500/20'
                                        : 'bg-red-50 dark:bg-red-500/10 border border-red-200 dark:border-red-500/20' }}">
                                    @if($result['success'] ?? false)
                                        <svg class="w-5 h-5 text-emerald-500 flex-shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="1.7" aria-hidden="true">
                                            <path stroke-linecap="round" stroke-linejoin="round" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z"/>
                                        </svg>
                                        <div>
                                            <p class="text-sm font-medium text-emerald-800 dark:text-emerald-300">{{ ucfirst($result['platform'] ?? '') }} — Published</p>
                                            @if(!empty($result['external_id']))
                                                <p class="text-xs text-emerald-600 dark:text-emerald-400 font-mono mt-0.5">ID: {{ $result['external_id'] }}</p>
                                            @endif
                                        </div>
                                    @else
                                        <svg class="w-5 h-5 text-red-500 flex-shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="1.7" aria-hidden="true">
                                            <path stroke-linecap="round" stroke-linejoin="round" d="M10 14l2-2m0 0l2-2m-2 2l-2-2m2 2l2 2m7-2a9 9 0 11-18 0 9 9 0 0118 0z"/>
                                        </svg>
                                        <div>
                                            <p class="text-sm font-medium text-red-700 dark:text-red-400">{{ ucfirst($result['platform'] ?? 'Unknown') }} — Failed</p>
                                            @if(!empty($result['message']))
                                                <p class="text-xs text-red-500 mt-0.5">{{ Str::limit($result['message'], 80) }}</p>
                                            @endif
                                        </div>
                                    @endif
                                </div>
                            @endforeach
                        </div>

                        @php $allSuccess = collect($pushResults)->every(fn($r) => $r['success'] ?? false); @endphp
                        @if($allSuccess && !empty($pushResults))
                            <div class="flex items-center gap-2 rounded-xl bg-emerald-50 dark:bg-emerald-500/10 border border-emerald-200 dark:border-emerald-500/20 px-4 py-3">
                                <svg class="w-5 h-5 text-emerald-500" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="1.7" aria-hidden="true">
                                    <path stroke-linecap="round" stroke-linejoin="round" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z"/>
                                </svg>
                                <p class="text-sm font-semibold text-emerald-800 dark:text-emerald-300">
                                    Published successfully on {{ count($pushResults) }} {{ Str::plural('platform', count($pushResults)) }}!
                                </p>
                            </div>
                        @endif
                    @endif

                </div>

                {{-- Footer --}}
                <div class="px-6 py-4 border-t border-gray-100 dark:border-gray-700 flex items-center justify-end gap-3">
                    @if($pushDone)
                        {{-- All platforms failed: give the user a way out --}}
                        <a href="{{ route('stores.products.index', $store) }}" wire:navigate class="btn btn-ghost">
                            Back to Products
                        </a>
                        <button wire:click="closePushModal" class="btn btn-secondary">
                            Close
                        </button>
                        <button wire:click="confirmPush" class="btn btn-primary">
                            <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="1.7" aria-hidden="true">
                                <path stroke-linecap="round" stroke-linejoin="round" d="M4 4v5h.582m15.356 2A8.001 8.001 0 004.582 9m0 0H9m11 11v-5h-.581m0 0a8.003 8.003 0 01-15.357-2m15.357 2H15"/>
                            </svg>
                            Retry
                        </button>
                    @elseif(!$isPushing)
                        <button wire:click="closePushModal" class="btn btn-ghost">Cancel</button>
                        <button wire:click="confirmPush"
                            @disabled(empty($selectedConnectionIds))
                            class="btn btn-primary disabled:opacity-50">
                            <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="1.7" aria-hidden="true">
                                <path stroke-linecap="round" stroke-linejoin="round" d="M7 16a4 4 0 01-.88-7.903A5 5 0 1115.9 6L16 6a5 5 0 011 9.9M15 13l-3-3m0 0l-3 3m3-3v12"/>
                            </svg>
                            Push to {{ count($selectedConnectionIds) }} {{ Str::plural('Platform', count($selectedConnectionIds)) }}
                        </button>
                    @endif
                </div>

            </div>
        </div>
    @endif

</div>
