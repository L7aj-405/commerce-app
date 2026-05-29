<div class="space-y-5">

    {{-- Header --}}
    <div class="flex items-center justify-between">
        <div class="flex items-center gap-3">
            <h2 class="text-base font-semibold text-gray-900 dark:text-white">Variants</h2>
            <span class="badge badge-indigo">
                {{ $variants->count() }} {{ Str::plural('variant', $variants->count()) }}
            </span>
        </div>
        <div class="flex items-center gap-2">
            <button wire:click="$toggle('showAttributeManager')" class="btn btn-secondary">
                <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="1.7" aria-hidden="true">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M7 7h.01M7 3h5c.512 0 1.024.195 1.414.586l7 7a2 2 0 010 2.828l-7 7a2 2 0 01-2.828 0l-7-7A1.994 1.994 0 013 12V7a4 4 0 014-4z"/>
                </svg>
                {{ $showAttributeManager ? 'Hide' : 'Attributes' }}
            </button>
            <button wire:click="openAddModal" class="btn btn-primary">
                <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="1.7" aria-hidden="true">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M12 4v16m8-8H4"/>
                </svg>
                Add Variant
            </button>
        </div>
    </div>

    {{-- Attribute Manager Panel --}}
    @if($showAttributeManager)
    <div class="bg-white dark:bg-gray-800 rounded-xl border border-gray-200 dark:border-gray-700 overflow-hidden">
        <div class="flex items-center justify-between px-5 py-4 border-b border-gray-100 dark:border-gray-700 bg-gray-50 dark:bg-gray-900/40">
            <div class="flex items-center gap-2">
                <svg class="w-4 h-4 text-indigo-500 dark:text-indigo-400" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="1.7" aria-hidden="true">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M7 7h.01M7 3h5c.512 0 1.024.195 1.414.586l7 7a2 2 0 010 2.828l-7 7a2 2 0 01-2.828 0l-7-7A1.994 1.994 0 013 12V7a4 4 0 014-4z"/>
                </svg>
                <h3 class="text-sm font-semibold text-gray-800 dark:text-gray-200">Attribute Manager</h3>
                <span class="text-xs text-gray-400 dark:text-gray-500">Define options like Size, Color, Material</span>
            </div>
        </div>

        <div class="px-5 py-4 space-y-4">

            {{-- Existing attributes --}}
            @if($productAttributes->isEmpty())
                <p class="text-sm text-gray-400 dark:text-gray-500 text-center py-4">No attributes yet. Add one below.</p>
            @else
                <div class="space-y-3">
                    @foreach($productAttributes as $attr)
                        <div class="border border-gray-100 dark:border-gray-700 rounded-xl p-4">
                            <div class="flex items-center justify-between mb-3">
                                <span class="text-sm font-semibold text-gray-800 dark:text-gray-200">{{ $attr->name }}</span>
                                <button wire:click="deleteAttribute('{{ $attr->id }}')"
                                    wire:confirm="Delete attribute '{{ $attr->name }}' and all its values?"
                                    class="btn-icon text-gray-400 hover:text-red-500 hover:bg-red-50 dark:hover:bg-red-500/10"
                                    aria-label="Delete attribute {{ $attr->name }}">
                                    <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="1.7" aria-hidden="true">
                                        <path stroke-linecap="round" stroke-linejoin="round" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16"/>
                                    </svg>
                                    <span class="sr-only">Delete attribute {{ $attr->name }}</span>
                                </button>
                            </div>

                            {{-- Existing values --}}
                            <div class="flex flex-wrap gap-1.5 mb-3">
                                @forelse($attr->values as $val)
                                    <span class="inline-flex items-center gap-1 px-2.5 py-1 bg-indigo-50 dark:bg-indigo-500/10 text-indigo-700 dark:text-indigo-300 border border-indigo-100 dark:border-indigo-500/20 rounded-lg text-xs font-medium">
                                        {{ $val->value }}
                                        <button wire:click="deleteAttributeValue('{{ $val->id }}')"
                                            class="text-indigo-400 hover:text-red-500 dark:hover:text-red-400 transition-colors ml-0.5 leading-none"
                                            aria-label="Remove value {{ $val->value }}">
                                            <svg class="w-3 h-3" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="2.5" aria-hidden="true">
                                                <path stroke-linecap="round" stroke-linejoin="round" d="M6 18L18 6M6 6l12 12"/>
                                            </svg>
                                        </button>
                                    </span>
                                @empty
                                    <span class="text-xs text-gray-400 dark:text-gray-500 italic">No values yet</span>
                                @endforelse
                            </div>

                            {{-- Add value input --}}
                            <div class="flex items-center gap-2">
                                <input type="text"
                                    wire:model="newAttrValueInputs.{{ $attr->id }}"
                                    wire:keydown.enter="addAttributeValue('{{ $attr->id }}')"
                                    placeholder="Add value…"
                                    class="input flex-1 py-1.5 text-sm">
                                <button wire:click="addAttributeValue('{{ $attr->id }}')"
                                    class="px-3 py-1.5 text-xs font-medium text-white bg-indigo-600 hover:bg-indigo-700 dark:bg-indigo-500 dark:hover:bg-indigo-600 rounded-lg transition-colors">
                                    Add
                                </button>
                            </div>
                        </div>
                    @endforeach
                </div>
            @endif

            {{-- Add new attribute --}}
            <div class="border-t border-gray-100 dark:border-gray-700 pt-4">
                <p class="text-xs font-semibold text-gray-500 dark:text-gray-400 uppercase tracking-wide mb-2">New Attribute</p>
                <div class="flex items-center gap-2">
                    <input type="text"
                        wire:model="newAttrName"
                        wire:keydown.enter="addAttribute"
                        placeholder="e.g., Size, Color, Material…"
                        class="input flex-1">
                    <button wire:click="addAttribute" class="btn btn-primary">
                        Add Attribute
                    </button>
                </div>
                @error('newAttrName') <p class="error-text mt-1">{{ $message }}</p> @enderror
            </div>

        </div>
    </div>
    @endif

    {{-- Variants Table --}}
    <div class="table-container">
        <div class="overflow-x-auto">
            <table class="w-full" aria-label="Product variants">
                <thead>
                    <tr>
                        <th scope="col" class="table-th">Variant</th>
                        <th scope="col" class="table-th">SKU</th>
                        <th scope="col" class="table-th">Price</th>
                        <th scope="col" class="table-th">Cost</th>
                        <th scope="col" class="table-th">Attributes</th>
                        <th scope="col" class="table-th">Stock</th>
                        <th scope="col" class="table-th text-right">
                            <span class="sr-only">Actions</span>
                        </th>
                    </tr>
                </thead>
                <tbody>
                    @forelse($variants as $variant)
                        <tr class="table-row">

                            {{-- Variant Name --}}
                            <td class="table-td">
                                <div class="font-medium text-gray-900 dark:text-white">{{ $variant->getDisplayName() }}</div>
                                @if($variant->compare_price > 0 && $variant->compare_price > $variant->price)
                                    <div class="text-xs text-gray-400 dark:text-gray-500 line-through mt-0.5">
                                        {{ number_format((float) $variant->compare_price, 2) }} MAD
                                    </div>
                                @endif
                            </td>

                            {{-- SKU --}}
                            <td class="table-td">
                                @if($variant->sku)
                                    <code class="px-2 py-0.5 bg-gray-100 dark:bg-gray-700 text-gray-700 dark:text-gray-300 rounded text-xs font-mono">{{ $variant->sku }}</code>
                                @else
                                    <span class="text-gray-300 dark:text-gray-600">—</span>
                                @endif
                            </td>

                            {{-- Price --}}
                            <td class="table-td font-semibold text-gray-900 dark:text-white tabular-nums">
                                {{ $variant->getFormattedPrice() }}
                            </td>

                            {{-- Cost --}}
                            <td class="table-td text-gray-500 dark:text-gray-400 tabular-nums">
                                {{ $variant->getFormattedCost() }}
                            </td>

                            {{-- Attributes (pivot-first) --}}
                            <td class="table-td">
                                @if($variant->attributeValues->isNotEmpty())
                                    <div class="flex flex-wrap gap-1">
                                        @foreach($variant->attributeValues->groupBy(fn ($v) => $v->attribute?->name ?? '') as $attrName => $vals)
                                            <x-variant-attribute-badge
                                                :name="$attrName"
                                                :value="$vals->pluck('value')->count() === 1 ? $vals->first()->value : $vals->pluck('value')->toArray()" />
                                        @endforeach
                                    </div>
                                @else
                                    @php $attrs = $variant->getAttribute('attributes') ?? []; @endphp
                                    @if(!empty($attrs))
                                        <div class="flex flex-wrap gap-1">
                                            @foreach($attrs as $name => $value)
                                                <x-variant-attribute-badge :name="$name" :value="$value" />
                                            @endforeach
                                        </div>
                                    @else
                                        <span class="text-gray-300 dark:text-gray-600 text-xs">—</span>
                                    @endif
                                @endif
                            </td>

                            {{-- Stock --}}
                            <td class="table-td">
                                @php $stock = $variant->getTotalStock(); @endphp
                                <span class="badge {{ $stock > 0 ? 'badge-green' : 'badge-red' }} tabular-nums">
                                    {{ $stock }}
                                </span>
                            </td>

                            {{-- Actions --}}
                            <td class="table-td">
                                <div class="flex items-center justify-end gap-1">
                                    <button wire:click="openEditModal('{{ $variant->id }}')"
                                        class="btn-icon text-gray-400 hover:text-blue-600 hover:bg-blue-50 dark:hover:bg-blue-500/10 dark:hover:text-blue-400"
                                        aria-label="Edit variant {{ $variant->getDisplayName() }}">
                                        <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="1.7" aria-hidden="true">
                                            <path stroke-linecap="round" stroke-linejoin="round" d="M11 5H6a2 2 0 00-2 2v11a2 2 0 002 2h11a2 2 0 002-2v-5m-1.414-9.414a2 2 0 112.828 2.828L11.828 15H9v-2.828l8.586-8.586z"/>
                                        </svg>
                                        <span class="sr-only">Edit variant {{ $variant->getDisplayName() }}</span>
                                    </button>
                                    <button wire:click="deleteVariant('{{ $variant->id }}')"
                                        wire:confirm="Delete this variant? This cannot be undone."
                                        class="btn-icon text-gray-400 hover:text-red-600 hover:bg-red-50 dark:hover:bg-red-500/10 dark:hover:text-red-400"
                                        aria-label="Delete variant {{ $variant->getDisplayName() }}">
                                        <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="1.7" aria-hidden="true">
                                            <path stroke-linecap="round" stroke-linejoin="round" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16"/>
                                        </svg>
                                        <span class="sr-only">Delete variant {{ $variant->getDisplayName() }}</span>
                                    </button>
                                </div>
                            </td>

                        </tr>
                    @empty
                        <tr>
                            <td colspan="7" class="px-5 py-16 text-center">
                                <div class="flex flex-col items-center gap-3">
                                    <div class="w-12 h-12 rounded-full bg-gray-100 dark:bg-gray-700 flex items-center justify-center">
                                        <svg class="w-6 h-6 text-gray-400" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="1.5" aria-hidden="true">
                                            <path stroke-linecap="round" stroke-linejoin="round" d="M7 21a4 4 0 01-4-4V5a2 2 0 012-2h4a2 2 0 012 2v12a4 4 0 01-4 4zm0 0h12a2 2 0 002-2v-4a2 2 0 00-2-2h-2.343M11 7.343l1.657-1.657a2 2 0 012.828 0l2.829 2.829a2 2 0 010 2.828l-8.486 8.485M7 17h.01"/>
                                        </svg>
                                    </div>
                                    <div>
                                        <p class="text-sm font-medium text-gray-900 dark:text-white">No variants yet</p>
                                        <p class="text-xs text-gray-400 dark:text-gray-500 mt-1">Add your first variant to define sizes, colors, or other options.</p>
                                    </div>
                                    <button wire:click="openAddModal" class="btn btn-primary mt-1">
                                        <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="1.7" aria-hidden="true">
                                            <path stroke-linecap="round" stroke-linejoin="round" d="M12 4v16m8-8H4"/>
                                        </svg>
                                        Add First Variant
                                    </button>
                                </div>
                            </td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </div>

    {{-- Modal --}}
    @include('livewire.products.variant-form-modal')

</div>
