<div class="space-y-5">

    {{-- Header --}}
    <div class="flex items-center justify-between">
        <div>
            <h2 class="text-base font-semibold text-gray-900 dark:text-white">Stock Management</h2>
            <p class="text-sm text-gray-400 dark:text-gray-500 mt-0.5">
                Total across all warehouses:
                <span class="font-semibold text-gray-700 dark:text-gray-300">{{ $product->getDisplayStock() }} units</span>
            </p>
        </div>
        <button wire:click="$toggle('showAdjustForm')"
            class="btn {{ $showAdjustForm ? 'btn-secondary' : 'btn-success' }}">
            @if($showAdjustForm)
                <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="1.7" aria-hidden="true">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M6 18L18 6M6 6l12 12"/>
                </svg>
                Cancel
            @else
                <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="1.7" aria-hidden="true">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M12 4v16m8-8H4"/>
                </svg>
                Adjust Stock
            @endif
        </button>
    </div>

    {{-- Adjust Form --}}
    @if($showAdjustForm)
        <div class="bg-emerald-50 dark:bg-emerald-500/10 border border-emerald-200 dark:border-emerald-500/20 rounded-xl p-5 space-y-4">
            <h3 class="text-sm font-semibold text-emerald-900 dark:text-emerald-300">Stock Adjustment</h3>

            <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                <div>
                    <label class="label">Warehouse <span class="text-red-500">*</span></label>
                    <select wire:model="selectedWarehouse" class="input mt-1.5">
                        <option value="">Select warehouse…</option>
                        @foreach($warehouses as $wh)
                            <option value="{{ $wh->id }}">{{ $wh->name }}</option>
                        @endforeach
                    </select>
                    @error('selectedWarehouse') <p class="error-text mt-1">{{ $message }}</p> @enderror
                </div>
                <div>
                    <label class="label">Quantity <span class="text-red-500">*</span></label>
                    <input type="number" wire:model="adjustQty" placeholder="e.g., 50" class="input mt-1.5">
                    @error('adjustQty') <p class="error-text mt-1">{{ $message }}</p> @enderror
                </div>
            </div>

            @if($product->isVariable())
            <div>
                <div class="flex items-center justify-between mb-2">
                    <label class="label">
                        Variants <span class="text-red-500">*</span>
                    </label>
                    @if($variants->isNotEmpty())
                        <span class="text-xs text-emerald-700 dark:text-emerald-400 font-medium">
                            {{ count($selectedVariants) > 0 ? count($selectedVariants) . ' selected' : 'None selected' }}
                        </span>
                    @endif
                </div>
                <div class="border border-gray-200 dark:border-gray-600 rounded-lg divide-y divide-gray-100 dark:divide-gray-700 overflow-hidden">
                    @foreach($variants as $variant)
                        <label class="flex items-center gap-3 px-4 py-2.5 cursor-pointer transition-colors
                            {{ in_array($variant->id, $selectedVariants)
                                ? 'bg-emerald-50 dark:bg-emerald-500/10'
                                : 'bg-white dark:bg-gray-800 hover:bg-emerald-50/60 dark:hover:bg-emerald-500/5' }}">
                            <input type="checkbox"
                                wire:model.live="selectedVariants"
                                value="{{ $variant->id }}"
                                class="w-4 h-4 text-emerald-600 border-gray-300 dark:border-gray-600 rounded focus:ring-emerald-500 dark:bg-gray-700">
                            <span class="text-sm text-gray-800 dark:text-gray-200">{{ $variant->getDisplayName() }}</span>
                            @if($variant->sku)
                                <span class="ml-auto text-xs text-gray-400 dark:text-gray-500 font-mono">{{ $variant->sku }}</span>
                            @endif
                        </label>
                    @endforeach
                </div>
                @error('selectedVariants') <p class="error-text mt-1">{{ $message }}</p> @enderror
            </div>
            @endif

            <div>
                <label class="label">Notes</label>
                <input type="text" wire:model="adjustNotes" placeholder="e.g., Stock arrival from supplier" class="input mt-1.5">
            </div>

            <div class="flex items-center justify-end gap-3 pt-1">
                <button wire:click="resetForm" class="btn btn-ghost">
                    Cancel
                </button>
                <button wire:click="adjustStockAll" class="btn btn-success">
                    Apply Adjustment
                </button>
            </div>
        </div>
    @endif

    {{-- Stock Table --}}
    <div class="table-container">
        <div class="overflow-x-auto">
            <table class="w-full" aria-label="Stock levels">
                <thead>
                    <tr>
                        <th scope="col" class="table-th">Warehouse</th>
                        <th scope="col" class="table-th">Product / Variant</th>
                        <th scope="col" class="table-th">On Hand</th>
                        <th scope="col" class="table-th">Available</th>
                        <th scope="col" class="table-th">Reserved</th>
                        <th scope="col" class="table-th text-right">Update</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse($stocks as $stock)
                        <tr class="table-row">

                            {{-- Warehouse --}}
                            <td class="table-td font-medium text-gray-900 dark:text-white">
                                {{ $stock->warehouse?->name ?? '—' }}
                            </td>

                            {{-- Product / Variant --}}
                            <td class="table-td text-gray-600 dark:text-gray-400">
                                @if($stock->variant)
                                    <div class="flex items-center gap-2">
                                        <svg class="w-3 h-3 text-gray-400 dark:text-gray-500 flex-shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="2" aria-hidden="true">
                                            <path stroke-linecap="round" stroke-linejoin="round" d="M9 5l7 7-7 7"/>
                                        </svg>
                                        <span>{{ $stock->variant->getDisplayName() }}</span>
                                    </div>
                                @else
                                    {{ $product->name }}
                                @endif
                            </td>

                            {{-- Qty input --}}
                            <td class="table-td">
                                <input type="number"
                                    wire:model.defer="quantities.{{ $stock->id }}"
                                    min="0"
                                    aria-label="Quantity for {{ $stock->variant?->getDisplayName() ?? $product->name }}"
                                    class="input w-20 py-1.5 text-sm text-center tabular-nums">
                            </td>

                            {{-- Available --}}
                            <td class="table-td">
                                <span class="badge {{ $stock->getAvailable() > 0 ? 'badge-green' : 'badge-red' }} tabular-nums">
                                    {{ $stock->getAvailable() }}
                                </span>
                            </td>

                            {{-- Reserved --}}
                            <td class="table-td text-gray-500 dark:text-gray-400 tabular-nums">
                                {{ $stock->reserved ?? 0 }}
                            </td>

                            {{-- Save button --}}
                            <td class="table-td text-right">
                                <button wire:click="updateStock('{{ $stock->id }}')"
                                    class="px-3 py-1.5 text-xs font-medium text-blue-600 dark:text-blue-400 border border-blue-200 dark:border-blue-500/30 bg-blue-50 dark:bg-blue-500/10 rounded-lg hover:bg-blue-600 dark:hover:bg-blue-500 hover:text-white dark:hover:text-white hover:border-blue-600 dark:hover:border-blue-500 transition-colors">
                                    Save
                                </button>
                            </td>

                        </tr>
                    @empty
                        <tr>
                            <td colspan="6" class="px-5 py-12 text-center">
                                <div class="flex flex-col items-center gap-2">
                                    <div class="w-12 h-12 rounded-full bg-gray-100 dark:bg-gray-700 flex items-center justify-center mb-1">
                                        <svg class="w-6 h-6 text-gray-400" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="1.5" aria-hidden="true">
                                            <path stroke-linecap="round" stroke-linejoin="round" d="M20 7l-8-4-8 4m16 0l-8 4m8-4v10l-8 4m0-10L4 7m8 4v10M4 7v10l8 4"/>
                                        </svg>
                                    </div>
                                    <p class="text-sm font-medium text-gray-900 dark:text-white">No stock records found</p>
                                    <p class="text-xs text-gray-400 dark:text-gray-500">Run a product sync to create stock records.</p>
                                </div>
                            </td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </div>

</div>
