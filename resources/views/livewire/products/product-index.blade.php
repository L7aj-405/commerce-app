<div class="space-y-6">

    {{-- Flash --}}
    @if(session('success'))
        <div class="flex items-center gap-3 rounded-xl bg-green-50 dark:bg-green-500/10 border border-green-200 dark:border-green-500/20 px-4 py-3 text-sm text-green-800 dark:text-green-300">
            <svg class="w-4 h-4 flex-shrink-0 text-green-500" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="1.7" aria-hidden="true">
                <path stroke-linecap="round" stroke-linejoin="round" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z"/>
            </svg>
            {{ session('success') }}
        </div>
    @endif

    {{-- Page Header --}}
    <div class="page-header">
        <div>
            <h1 class="page-title">Products</h1>
            <p class="page-subtitle">
                {{ $store->name }}
                @if($lastSyncTime)
                    <span class="ml-1">· Last synced {{ $lastSyncTime }}</span>
                @endif
            </p>
        </div>
        <div class="flex items-center gap-3">
            <livewire:products.product-sync :store="$store" />
            <a href="{{ route('stores.products.create', $store) }}" class="btn-primary">
                <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="2" aria-hidden="true">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M12 4v16m8-8H4"/>
                </svg>
                Add Product
            </a>
        </div>
    </div>

    {{-- Filters --}}
    <div class="filter-bar">
        <div class="grid grid-cols-1 md:grid-cols-3 gap-3">
            <div class="relative">
                <svg class="w-4 h-4 absolute left-3 top-3 text-gray-400 pointer-events-none" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="1.7" aria-hidden="true">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z"/>
                </svg>
                <input type="text"
                       placeholder="Search products…"
                       wire:model.live="search"
                       aria-label="Search products"
                       class="input pl-9">
            </div>

            <select wire:model.live="status" aria-label="Filter by status" class="input">
                <option value="">All Status</option>
                <option value="active">Active</option>
                <option value="draft">Draft</option>
                <option value="archived">Archived</option>
            </select>

            <select wire:model.live="type" aria-label="Filter by type" class="input">
                <option value="">All Types</option>
                <option value="simple">Simple</option>
                <option value="variable">Variable</option>
            </select>
        </div>
    </div>

    {{-- Table --}}
    <div class="table-container">
        <div class="overflow-x-auto">
            <table class="w-full" aria-label="Products table">
                <thead>
                    <tr>
                        <th scope="col" class="table-th">Name</th>
                        <th scope="col" class="table-th">SKU</th>
                        <th scope="col" class="table-th">Type</th>
                        <th scope="col" class="table-th">Price</th>
                        <th scope="col" class="table-th">Stock</th>
                        <th scope="col" class="table-th">Variants</th>
                        <th scope="col" class="table-th">Status</th>
                        <th scope="col" class="table-th">
                            <span class="sr-only">Actions</span>
                        </th>
                    </tr>
                </thead>
                <tbody>
                    @forelse($products as $product)
                        <tr class="table-row">
                            <td class="table-td font-medium text-gray-900 dark:text-white">
                                {{ $product->name }}
                            </td>
                            <td class="table-td text-gray-500 dark:text-gray-400 font-mono text-xs">
                                {{ $product->sku }}
                            </td>
                            <td class="table-td">
                                <span class="badge {{ $product->type === 'simple' ? 'badge-blue' : 'badge-purple' }}">
                                    {{ ucfirst($product->type) }}
                                </span>
                            </td>
                            <td class="table-td font-semibold text-gray-900 dark:text-white tabular-nums">
                                ${{ number_format($product->price, 2) }}
                            </td>
                            <td class="table-td text-gray-600 dark:text-gray-300 tabular-nums">
                                {{ $product->getTotalStock() }}
                            </td>
                            <td class="table-td">
                                @if($product->variants_count > 0)
                                    <span class="badge badge-indigo">{{ $product->variants_count }}</span>
                                @else
                                    <span class="text-gray-300 dark:text-gray-600">—</span>
                                @endif
                            </td>
                            <td class="table-td">
                                <span class="badge
                                    {{ $product->status === 'active'   ? 'badge-green'  : '' }}
                                    {{ $product->status === 'draft'    ? 'badge-yellow' : '' }}
                                    {{ $product->status === 'archived' ? 'badge-gray'   : '' }}">
                                    <span class="w-1.5 h-1.5 rounded-full
                                        {{ $product->status === 'active'   ? 'bg-emerald-500' : '' }}
                                        {{ $product->status === 'draft'    ? 'bg-amber-500'   : '' }}
                                        {{ $product->status === 'archived' ? 'bg-gray-400'    : '' }}">
                                    </span>
                                    {{ ucfirst($product->status) }}
                                </span>
                            </td>
                            <td class="table-td">
                                <div class="flex items-center justify-end gap-1">
                                    <a href="{{ route('stores.products.edit', [$store, $product]) }}"
                                       class="btn-icon text-blue-600 dark:text-blue-400 hover:bg-blue-50 dark:hover:bg-blue-500/10"
                                       title="Edit">
                                        <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="1.7" aria-hidden="true">
                                            <path stroke-linecap="round" stroke-linejoin="round" d="M11 5H6a2 2 0 00-2 2v11a2 2 0 002 2h11a2 2 0 002-2v-5m-1.414-9.414a2 2 0 112.828 2.828L11.828 15H9v-2.828l8.586-8.586z"/>
                                        </svg>
                                        <span class="sr-only">Edit {{ $product->name }}</span>
                                    </a>
                                    <button wire:click="duplicateProduct('{{ $product->id }}')"
                                            class="btn-icon text-emerald-600 dark:text-emerald-400 hover:bg-emerald-50 dark:hover:bg-emerald-500/10"
                                            title="Duplicate">
                                        <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="1.7" aria-hidden="true">
                                            <path stroke-linecap="round" stroke-linejoin="round" d="M8 16H6a2 2 0 01-2-2V6a2 2 0 012-2h8a2 2 0 012 2v2m-6 12h8a2 2 0 002-2v-8a2 2 0 00-2-2h-8a2 2 0 00-2 2v8a2 2 0 002 2z"/>
                                        </svg>
                                        <span class="sr-only">Duplicate {{ $product->name }}</span>
                                    </button>
                                    <button wire:click="deleteProduct('{{ $product->id }}')"
                                            class="btn-icon text-red-500 hover:bg-red-50 dark:hover:bg-red-500/10"
                                            title="Delete"
                                            onclick="return confirm('Delete this product?')">
                                        <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="1.7" aria-hidden="true">
                                            <path stroke-linecap="round" stroke-linejoin="round" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16"/>
                                        </svg>
                                        <span class="sr-only">Delete {{ $product->name }}</span>
                                    </button>
                                </div>
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="8" class="px-5 py-16">
                                <div class="flex flex-col items-center justify-center gap-3 text-center">
                                    <div class="w-12 h-12 bg-gray-100 dark:bg-gray-700 rounded-full flex items-center justify-center">
                                        <svg class="w-6 h-6 text-gray-400" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="1.5" aria-hidden="true">
                                            <path stroke-linecap="round" stroke-linejoin="round" d="M20 7l-8-4-8 4m16 0l-8 4m8-4v10l-8 4m0-10L4 7m8 4v10M4 7v10l8 4"/>
                                        </svg>
                                    </div>
                                    <div>
                                        <p class="text-sm font-medium text-gray-900 dark:text-white">No products found</p>
                                        <p class="text-xs text-gray-500 dark:text-gray-400 mt-0.5">Try adjusting your filters or add a new product</p>
                                    </div>
                                </div>
                            </td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>

        {{-- Pagination --}}
        @if($products->hasPages())
            <div class="px-5 py-4 border-t border-gray-100 dark:border-gray-700">
                {{ $products->links() }}
            </div>
        @endif
    </div>

</div>
