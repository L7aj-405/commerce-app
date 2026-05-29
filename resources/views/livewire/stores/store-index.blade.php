<div class="space-y-6">

    {{-- Page Header --}}
    <div class="page-header">
        <div>
            <h1 class="page-title">My Stores</h1>
            <p class="page-subtitle">Manage your online, physical, and hybrid stores</p>
        </div>
        <a href="{{ route('stores.create') }}" wire:navigate class="btn btn-primary flex-shrink-0">
            <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="1.7" aria-hidden="true">
                <path stroke-linecap="round" stroke-linejoin="round" d="M12 4v16m8-8H4"/>
            </svg>
            New Store
        </a>
    </div>

    {{-- Filters --}}
    <div class="filter-bar">
        <div class="grid grid-cols-1 md:grid-cols-3 gap-3">
            <div class="relative">
                <svg class="w-4 h-4 absolute left-3 top-3 text-gray-400 pointer-events-none" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="1.7" aria-hidden="true">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z"/>
                </svg>
                <input type="search"
                    wire:model.live.debounce.300ms="search"
                    placeholder="Search stores…"
                    aria-label="Search stores"
                    class="input pl-9">
            </div>

            <select wire:model.live="typeFilter" aria-label="Filter by type" class="input">
                <option value="">All Types</option>
                @foreach($storeTypes as $type)
                    <option value="{{ $type->value }}">{{ $type->label() }}</option>
                @endforeach
            </select>

            <select wire:model.live="statusFilter" aria-label="Filter by status" class="input">
                <option value="">All Status</option>
                @foreach($storeStatuses as $status)
                    <option value="{{ $status->value }}">{{ $status->label() }}</option>
                @endforeach
            </select>
        </div>
    </div>

    {{-- Stores Table --}}
    @if($stores->count() > 0)
        <div class="table-container">
            <div class="overflow-x-auto">
                <table class="w-full" aria-label="Stores table">
                    <thead>
                        <tr>
                            <th scope="col" class="table-th">Store</th>
                            <th scope="col" class="table-th">Type</th>
                            <th scope="col" class="table-th">Status</th>
                            <th scope="col" class="table-th">Currency</th>
                            <th scope="col" class="table-th">Created</th>
                            <th scope="col" class="table-th text-right">
                                <span class="sr-only">Actions</span>
                            </th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach($stores as $store)
                            <tr class="table-row">
                                <td class="table-td">
                                    <p class="font-medium text-gray-900 dark:text-white">{{ $store->name }}</p>
                                    <p class="text-xs text-gray-400 dark:text-gray-500 mt-0.5">{{ $store->slug }}</p>
                                </td>
                                <td class="table-td">
                                    <span class="badge
                                        @if($store->type === \App\Enums\StoreType::Online)   badge-blue
                                        @elseif($store->type === \App\Enums\StoreType::Physical) badge-green
                                        @else badge-purple
                                        @endif">
                                        {{ $store->type->label() }}
                                    </span>
                                </td>
                                <td class="table-td">
                                    <span class="badge
                                        @if($store->status === \App\Enums\StoreStatus::Active)   badge-green
                                        @elseif($store->status === \App\Enums\StoreStatus::Inactive) badge-gray
                                        @else badge-red
                                        @endif">
                                        {{ $store->status->label() }}
                                    </span>
                                </td>
                                <td class="table-td text-gray-600 dark:text-gray-400 tabular-nums">
                                    {{ $store->currency }}
                                </td>
                                <td class="table-td text-gray-500 dark:text-gray-400 text-xs">
                                    {{ $store->created_at->diffForHumans() }}
                                </td>
                                <td class="table-td text-right">
                                    <div class="flex items-center justify-end gap-1">
                                        <a href="{{ route('stores.products.index', $store) }}"
                                            wire:navigate
                                            class="btn-icon text-gray-400 hover:text-violet-600 hover:bg-violet-50 dark:hover:bg-violet-500/10 dark:hover:text-violet-400"
                                            aria-label="Products for {{ $store->name }}">
                                            <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="1.7" aria-hidden="true">
                                                <path stroke-linecap="round" stroke-linejoin="round" d="M20 7l-8-4-8 4m16 0l-8 4m8-4v10l-8 4m0-10L4 7m8 4v10M4 7v10l8 4"/>
                                            </svg>
                                            <span class="sr-only">Products for {{ $store->name }}</span>
                                        </a>
                                        <a href="{{ route('stores.connections.index', $store) }}"
                                            wire:navigate
                                            class="btn-icon text-gray-400 hover:text-indigo-600 hover:bg-indigo-50 dark:hover:bg-indigo-500/10 dark:hover:text-indigo-400"
                                            aria-label="Connections for {{ $store->name }}">
                                            <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="1.7" aria-hidden="true">
                                                <path stroke-linecap="round" stroke-linejoin="round" d="M13.828 10.172a4 4 0 00-5.656 0l-4 4a4 4 0 105.656 5.656l1.102-1.101m-.758-4.899a4 4 0 005.656 0l4-4a4 4 0 00-5.656-5.656l-1.1 1.1"/>
                                            </svg>
                                            <span class="sr-only">Connections for {{ $store->name }}</span>
                                        </a>
                                        <a href="{{ route('stores.edit', $store) }}"
                                            wire:navigate
                                            class="btn-icon text-gray-400 hover:text-blue-600 hover:bg-blue-50 dark:hover:bg-blue-500/10 dark:hover:text-blue-400"
                                            aria-label="Edit {{ $store->name }}">
                                            <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="1.7" aria-hidden="true">
                                                <path stroke-linecap="round" stroke-linejoin="round" d="M11 5H6a2 2 0 00-2 2v11a2 2 0 002 2h11a2 2 0 002-2v-5m-1.414-9.414a2 2 0 112.828 2.828L11.828 15H9v-2.828l8.586-8.586z"/>
                                            </svg>
                                            <span class="sr-only">Edit {{ $store->name }}</span>
                                        </a>
                                        <button wire:click="deleteStore('{{ $store->id }}')"
                                            wire:confirm="Are you sure you want to delete {{ $store->name }}?"
                                            class="btn-icon text-gray-400 hover:text-red-600 hover:bg-red-50 dark:hover:bg-red-500/10 dark:hover:text-red-400"
                                            aria-label="Delete {{ $store->name }}">
                                            <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="1.7" aria-hidden="true">
                                                <path stroke-linecap="round" stroke-linejoin="round" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16"/>
                                            </svg>
                                            <span class="sr-only">Delete {{ $store->name }}</span>
                                        </button>
                                    </div>
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>

            {{-- Pagination --}}
            @if($stores->hasPages())
                <div class="px-5 py-4 border-t border-gray-100 dark:border-gray-700">
                    {{ $stores->links() }}
                </div>
            @endif
        </div>
    @else
        {{-- Empty State --}}
        <div class="card flex flex-col items-center justify-center py-16 text-center">
            <div class="w-14 h-14 bg-gray-100 dark:bg-gray-700 rounded-full flex items-center justify-center mb-4">
                <svg class="w-7 h-7 text-gray-400" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="1.5" aria-hidden="true">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M19 21V5a2 2 0 00-2-2H7a2 2 0 00-2 2v16m14 0h2m-2 0h-5m-9 0H3m2 0h5M9 7h1m-1 4h1m4-4h1m-1 4h1m-5 10v-5a1 1 0 011-1h2a1 1 0 011 1v5m-4 0h4"/>
                </svg>
            </div>
            <h3 class="text-sm font-semibold text-gray-900 dark:text-white">No stores yet</h3>
            <p class="text-sm text-gray-500 dark:text-gray-400 mt-1 max-w-xs">
                Get started by creating your first store and connecting a platform.
            </p>
            <a href="{{ route('stores.create') }}" wire:navigate class="btn btn-primary mt-5">
                <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="1.7" aria-hidden="true">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M12 4v16m8-8H4"/>
                </svg>
                Create First Store
            </a>
        </div>
    @endif

</div>
