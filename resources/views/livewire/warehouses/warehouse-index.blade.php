<div class="space-y-6">
    <!-- Page Title -->
    <div class="flex items-center justify-between">
        <div>
            <h1 class="text-2xl font-bold text-gray-900">Warehouses</h1>
            <p class="text-gray-600 mt-1">Manage your inventory warehouses</p>
        </div>
        <a href="{{ route('warehouses.create') }}" 
           class="bg-blue-600 text-white px-4 py-2 rounded-lg hover:bg-blue-700 transition">
            + Add Warehouse
        </a>
    </div>

    <!-- Warehouses Grid -->
    <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-6">
        @forelse($warehouses as $warehouse)
            <div class="bg-white rounded-2xl border border-gray-200 p-6 hover:shadow-lg transition">
                <div class="flex items-start justify-between mb-4">
                    <div>
                        <h3 class="text-lg font-bold text-gray-900">{{ $warehouse->name }}</h3>
                        <p class="text-sm text-gray-600 mt-1">{{ $warehouse->location }}</p>
                    </div>
                    @if($warehouse->is_default)
                        <span class="bg-blue-100 text-blue-800 px-2 py-1 rounded text-xs font-semibold">Default</span>
                    @endif
                </div>

                <div class="space-y-2 mb-6 text-sm text-gray-600">
                    <p>📍 {{ $warehouse->getFullAddress() }}</p>
                    <p>📦 {{ $warehouse->getTotalProducts() }} products in stock</p>
                    <p>🏢 Serves {{ $warehouse->stores->count() }} store(s)</p>
                </div>

                <div class="flex gap-2">
                    <a href="{{ route('warehouses.edit', $warehouse) }}" 
                       class="flex-1 bg-blue-100 text-blue-600 px-3 py-2 rounded-lg text-center text-sm font-medium hover:bg-blue-200 transition">
                        Edit
                    </a>
                    @if(!$warehouse->is_default)
                        <button 
                            wire:click="setDefault('{{ $warehouse->id }}')"
                            class="flex-1 bg-gray-100 text-gray-600 px-3 py-2 rounded-lg text-sm font-medium hover:bg-gray-200 transition">
                            Set Default
                        </button>
                    @endif
                    <button 
                        wire:click="deleteWarehouse('{{ $warehouse->id }}')"
                        class="flex-1 bg-red-100 text-red-600 px-3 py-2 rounded-lg text-sm font-medium hover:bg-red-200 transition"
                        onclick="return confirm('Are you sure?')">
                        Delete
                    </button>
                </div>
            </div>
        @empty
            <div class="col-span-full bg-white rounded-2xl border border-gray-200 p-12 text-center">
                <svg class="w-16 h-16 text-gray-400 mx-auto mb-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 16h-1v-4h-1m1-4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z"></path>
                </svg>
                <h3 class="text-gray-900 font-semibold">No warehouses yet</h3>
                <p class="text-gray-600 mt-1">Get started by adding your first warehouse</p>
                <a href="{{ route('warehouses.create') }}" 
                   class="inline-block bg-blue-600 text-white px-4 py-2 rounded-lg hover:bg-blue-700 transition mt-4">
                    Add Your First Warehouse
                </a>
            </div>
        @endforelse
    </div>
</div>