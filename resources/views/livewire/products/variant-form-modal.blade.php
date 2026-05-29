@if($showModal)
{{-- Backdrop --}}
<div class="fixed inset-0 z-50 flex items-center justify-center p-4">
    <div class="absolute inset-0 bg-gray-900/60 backdrop-blur-sm" wire:click="closeModal"></div>

    {{-- Modal Panel --}}
    <div class="relative bg-white rounded-2xl shadow-2xl w-full max-w-xl max-h-[90vh] flex flex-col"
         x-trap.noscroll="true">

        {{-- Modal Header --}}
        <div class="flex items-center justify-between px-6 py-5 border-b border-gray-100">
            <div>
                <h3 class="text-lg font-semibold text-gray-900">
                    {{ $editingVariantId ? 'Edit Variant' : 'Add Variant' }}
                </h3>
                <p class="text-sm text-gray-500 mt-0.5">
                    {{ $editingVariantId ? 'Update the variant details below.' : 'Fill in the details to create a new variant.' }}
                </p>
            </div>
            <button wire:click="closeModal"
                class="p-2 text-gray-400 hover:text-gray-600 hover:bg-gray-100 rounded-lg transition-colors">
                <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"/>
                </svg>
            </button>
        </div>

        {{-- Modal Body --}}
        <div class="flex-1 overflow-y-auto px-6 py-5 space-y-5">

            {{-- Variant Name --}}
            <div>
                <label class="block text-sm font-medium text-gray-700 mb-1.5">
                    Variant Name
                    <span class="text-gray-400 font-normal text-xs ml-1">(auto-filled from attributes if empty)</span>
                </label>
                <input type="text" wire:model="variantName" placeholder="e.g., Red — Large"
                    class="w-full px-3.5 py-2.5 border border-gray-300 rounded-lg text-sm focus:outline-none focus:ring-2 focus:ring-blue-500 focus:border-transparent transition">
            </div>

            {{-- SKU --}}
            <div>
                <label class="block text-sm font-medium text-gray-700 mb-1.5">
                    SKU <span class="text-red-500">*</span>
                </label>
                <input type="text" wire:model="variantSku" placeholder="e.g., TS-RED-LG"
                    class="w-full px-3.5 py-2.5 border border-gray-300 rounded-lg text-sm focus:outline-none focus:ring-2 focus:ring-blue-500 focus:border-transparent transition">
                @error('variantSku') <p class="mt-1 text-xs text-red-600">{{ $message }}</p> @enderror
            </div>

            {{-- Initial Stock (add only) --}}
            @if(!$editingVariantId)
            <div>
                <label class="block text-sm font-medium text-gray-700 mb-1.5">
                    Initial Stock
                    <span class="text-gray-400 font-normal text-xs ml-1">(units on hand)</span>
                </label>
                <input type="number" wire:model.defer="variantInitialQty" min="0" placeholder="0"
                    class="w-32 px-3.5 py-2.5 border border-gray-300 rounded-lg text-sm focus:outline-none focus:ring-2 focus:ring-blue-500 focus:border-transparent transition">
            </div>
            @endif

            {{-- Price / Cost / Compare --}}
            <div class="grid grid-cols-3 gap-3">
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1.5">Price <span class="text-red-500">*</span></label>
                    <div class="relative">
                        <input type="number" wire:model="variantPrice" step="0.01" min="0" placeholder="0.00"
                            class="w-full px-3 py-2.5 pr-11 border border-gray-300 rounded-lg text-sm focus:outline-none focus:ring-2 focus:ring-blue-500 focus:border-transparent transition">
                        <span class="absolute right-2.5 top-1/2 -translate-y-1/2 text-xs text-gray-400">MAD</span>
                    </div>
                    @error('variantPrice') <p class="mt-1 text-xs text-red-600">{{ $message }}</p> @enderror
                </div>
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1.5">Cost</label>
                    <div class="relative">
                        <input type="number" wire:model="variantCost" step="0.01" min="0" placeholder="0.00"
                            class="w-full px-3 py-2.5 pr-11 border border-gray-300 rounded-lg text-sm focus:outline-none focus:ring-2 focus:ring-blue-500 focus:border-transparent transition">
                        <span class="absolute right-2.5 top-1/2 -translate-y-1/2 text-xs text-gray-400">MAD</span>
                    </div>
                </div>
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1.5">Compare at</label>
                    <div class="relative">
                        <input type="number" wire:model="variantComparePrice" step="0.01" min="0" placeholder="0.00"
                            class="w-full px-3 py-2.5 pr-11 border border-gray-300 rounded-lg text-sm focus:outline-none focus:ring-2 focus:ring-blue-500 focus:border-transparent transition">
                        <span class="absolute right-2.5 top-1/2 -translate-y-1/2 text-xs text-gray-400">MAD</span>
                    </div>
                </div>
            </div>

            {{-- Attribute Checkboxes --}}
            <div>
                <div class="flex items-center justify-between mb-3">
                    <label class="text-sm font-medium text-gray-700">Attributes</label>
                    @if($productAttributes->isNotEmpty())
                        <span class="text-xs text-gray-400">
                            {{ count($selectedValueIds) }} {{ count($selectedValueIds) === 1 ? 'value' : 'values' }} selected
                        </span>
                    @endif
                </div>

                @if($productAttributes->isEmpty())
                    <div class="flex flex-col items-center justify-center py-6 border-2 border-dashed border-gray-200 rounded-xl">
                        <svg class="w-8 h-8 text-gray-300 mb-2" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M7 7h.01M7 3h5c.512 0 1.024.195 1.414.586l7 7a2 2 0 010 2.828l-7 7a2 2 0 01-2.828 0l-7-7A1.994 1.994 0 013 12V7a4 4 0 014-4z"/>
                        </svg>
                        <p class="text-sm text-gray-400">No attributes defined yet</p>
                        <p class="text-xs text-gray-400 mt-1">Close this modal and use the Attribute Manager to add attributes first.</p>
                    </div>
                @else
                    <div class="space-y-3">
                        @foreach($productAttributes as $attr)
                            <div class="border border-gray-100 rounded-xl p-4 bg-gray-50/50">
                                <p class="text-xs font-semibold text-gray-500 uppercase tracking-wide mb-2.5">{{ $attr->name }}</p>
                                @if($attr->values->isEmpty())
                                    <p class="text-xs text-gray-400 italic">No values — add some via the Attribute Manager</p>
                                @else
                                    <div class="flex flex-wrap gap-x-5 gap-y-2">
                                        @foreach($attr->values as $val)
                                            <label class="inline-flex items-center gap-2 cursor-pointer select-none group">
                                                <input type="checkbox"
                                                    wire:model.live="selectedValueIds"
                                                    value="{{ $val->id }}"
                                                    class="w-4 h-4 rounded border-gray-300 text-blue-600 focus:ring-blue-500 cursor-pointer">
                                                <span class="text-sm text-gray-700 group-hover:text-gray-900 transition-colors">
                                                    {{ $val->value }}
                                                </span>
                                            </label>
                                        @endforeach
                                    </div>
                                @endif
                            </div>
                        @endforeach
                    </div>
                @endif
            </div>

        </div>

        {{-- Modal Footer --}}
        <div class="flex items-center justify-end gap-3 px-6 py-4 border-t border-gray-100 bg-gray-50 rounded-b-2xl">
            <button type="button" wire:click="closeModal"
                class="px-4 py-2 text-sm font-medium text-gray-700 bg-white border border-gray-200 rounded-lg hover:bg-gray-50 transition-colors">
                Cancel
            </button>
            <button type="button" wire:click="saveVariant"
                class="px-5 py-2 text-sm font-medium text-white bg-blue-600 rounded-lg hover:bg-blue-700 transition-colors shadow-sm">
                <span wire:loading.remove wire:target="saveVariant">
                    {{ $editingVariantId ? 'Update Variant' : 'Add Variant' }}
                </span>
                <span wire:loading wire:target="saveVariant" class="flex items-center gap-2">
                    <svg class="animate-spin w-4 h-4" fill="none" viewBox="0 0 24 24">
                        <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"/>
                        <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4z"/>
                    </svg>
                    Saving…
                </span>
            </button>
        </div>

    </div>
</div>
@endif
