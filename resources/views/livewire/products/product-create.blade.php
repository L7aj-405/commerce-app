<div class="space-y-6">
    <!-- Page Title -->
    <div class="flex items-center justify-between">
        <div>
            <h1 class="text-2xl font-bold text-gray-900">Create Product</h1>
            <p class="text-gray-600 mt-1">Add a new product to {{ $store->name }}</p>
        </div>
        <a href="{{ route('stores.products.index', $store) }}" 
           class="text-gray-600 hover:text-gray-900">
            ← Back
        </a>
    </div>

    <!-- Form -->
    <form wire:submit="create" class="bg-white rounded-2xl border border-gray-200 p-8 space-y-6">
        <!-- Basic Info -->
        <div class="space-y-4">
            <h2 class="text-lg font-bold text-gray-900">Basic Information</h2>

            <div>
                <label class="block text-sm font-medium text-gray-700 mb-1">Product Name *</label>
                <input 
                    type="text" 
                    wire:model="name"
                    placeholder="e.g., Red T-Shirt"
                    class="w-full px-4 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500 focus:border-transparent"
                >
                @error('name') <span class="text-red-600 text-sm">{{ $message }}</span> @enderror
            </div>

            <div class="grid grid-cols-2 gap-4">
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1">SKU *</label>
                    <input 
                        type="text" 
                        wire:model="sku"
                        placeholder="e.g., TS-RED-001"
                        class="w-full px-4 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500 focus:border-transparent"
                    >
                    @error('sku') <span class="text-red-600 text-sm">{{ $message }}</span> @enderror
                </div>

                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1">Type *</label>
                    <select wire:model="type" class="w-full px-4 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500 focus:border-transparent">
                        <option value="simple">Simple Product</option>
                        <option value="variable">Variable Product</option>
                    </select>
                </div>
            </div>

            <div>
                <label class="block text-sm font-medium text-gray-700 mb-1">Description</label>
                <textarea 
                    wire:model="description"
                    rows="4"
                    placeholder="Product description..."
                    class="w-full px-4 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500 focus:border-transparent"
                ></textarea>
            </div>
        </div>

        <!-- Pricing -->
        <div class="space-y-4 pt-6 border-t border-gray-200">
            <h2 class="text-lg font-bold text-gray-900">Pricing</h2>

            <div class="grid grid-cols-2 gap-4">
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1">Price *</label>
                    <input 
                        type="number" 
                        wire:model="price"
                        step="0.01"
                        min="0"
                        class="w-full px-4 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500 focus:border-transparent"
                    >
                    @error('price') <span class="text-red-600 text-sm">{{ $message }}</span> @enderror
                </div>

                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1">Cost *</label>
                    <input 
                        type="number" 
                        wire:model="cost"
                        step="0.01"
                        min="0"
                        class="w-full px-4 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500 focus:border-transparent"
                    >
                    @error('cost') <span class="text-red-600 text-sm">{{ $message }}</span> @enderror
                </div>
            </div>
        </div>

        <!-- Stock -->
        <div class="space-y-4 pt-6 border-t border-gray-200">
            <h2 class="text-lg font-bold text-gray-900">Initial Stock</h2>

            <div class="grid grid-cols-2 gap-4">
            <div>
    <label class="block text-sm font-medium text-gray-700 mb-1">Warehouse</label>
    <select wire:model="warehouse_id" class="w-full px-4 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500 focus:border-transparent">
        <option value="">Select Warehouse (Optional)</option>
        @foreach($warehouses as $wh)
            <option value="{{ $wh->id }}">{{ $wh->name }}</option>
        @endforeach
    </select>
    @error('warehouse_id') <span class="text-red-600 text-sm">{{ $message }}</span> @enderror
</div>

                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1">Quantity *</label>
                    <input 
                        type="number" 
                        wire:model="quantity"
                        min="0"
                        class="w-full px-4 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500 focus:border-transparent"
                    >
                    @error('quantity') <span class="text-red-600 text-sm">{{ $message }}</span> @enderror
                </div>
            </div>
        </div>

        <!-- Buttons -->
        <div class="flex gap-4 pt-6 border-t border-gray-200">
            <a href="{{ route('stores.products.index', $store) }}" 
               class="flex-1 px-4 py-2 border border-gray-300 text-gray-700 rounded-lg hover:bg-gray-50 transition">
                Cancel
            </a>
            <button 
                type="submit"
                class="flex-1 px-4 py-2 bg-blue-600 text-white rounded-lg hover:bg-blue-700 transition font-medium">
                Create Product
            </button>
        </div>
    </form>
</div>