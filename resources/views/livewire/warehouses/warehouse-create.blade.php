<div class="space-y-6">
    <!-- Page Title -->
    <div class="flex items-center justify-between">
        <div>
            <h1 class="text-2xl font-bold text-gray-900">Create Warehouse</h1>
            <p class="text-gray-600 mt-1">Add a new warehouse to manage inventory</p>
        </div>
        <a href="{{ route('warehouses.index') }}" 
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
                <label class="block text-sm font-medium text-gray-700 mb-1">Warehouse Name *</label>
                <input 
                    type="text" 
                    wire:model="name"
                    placeholder="e.g., New York Warehouse"
                    class="w-full px-4 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500"
                >
                @error('name') <span class="text-red-600 text-sm">{{ $message }}</span> @enderror
            </div>

            <div>
                <label class="block text-sm font-medium text-gray-700 mb-1">Location</label>
                <input 
                    type="text" 
                    wire:model="location"
                    placeholder="e.g., Main Office"
                    class="w-full px-4 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500"
                >
            </div>
        </div>

        <!-- Address -->
        <div class="space-y-4 pt-6 border-t border-gray-200">
            <h2 class="text-lg font-bold text-gray-900">Address</h2>

            <div>
                <label class="block text-sm font-medium text-gray-700 mb-1">Address</label>
                <input 
                    type="text" 
                    wire:model="address"
                    placeholder="Street address"
                    class="w-full px-4 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500"
                >
            </div>

            <div class="grid grid-cols-2 gap-4">
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1">City</label>
                    <input 
                        type="text" 
                        wire:model="city"
                        class="w-full px-4 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500"
                    >
                </div>
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1">State</label>
                    <input 
                        type="text" 
                        wire:model="state"
                        class="w-full px-4 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500"
                    >
                </div>
            </div>

            <div class="grid grid-cols-2 gap-4">
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1">Country</label>
                    <input 
                        type="text" 
                        wire:model="country"
                        class="w-full px-4 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500"
                    >
                </div>
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1">ZIP Code</label>
                    <input 
                        type="text" 
                        wire:model="zip"
                        class="w-full px-4 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500"
                    >
                </div>
            </div>
        </div>

        <!-- Contact -->
        <div class="space-y-4 pt-6 border-t border-gray-200">
            <h2 class="text-lg font-bold text-gray-900">Contact Information</h2>

            <div class="grid grid-cols-2 gap-4">
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1">Phone</label>
                    <input 
                        type="tel" 
                        wire:model="phone"
                        class="w-full px-4 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500"
                    >
                </div>
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1">Email</label>
                    <input 
                        type="email" 
                        wire:model="email"
                        class="w-full px-4 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500"
                    >
                </div>
            </div>
        </div>

        <!-- Options -->
        <div class="space-y-4 pt-6 border-t border-gray-200">
            <h2 class="text-lg font-bold text-gray-900">Options</h2>

            <div class="flex items-center gap-3">
                <input 
                    type="checkbox" 
                    wire:model="is_active"
                    class="w-4 h-4 text-blue-600 border-gray-300 rounded"
                    id="is_active"
                >
                <label for="is_active" class="text-sm font-medium text-gray-700">Active</label>
            </div>

            <div class="flex items-center gap-3">
                <input 
                    type="checkbox" 
                    wire:model="is_default"
                    class="w-4 h-4 text-blue-600 border-gray-300 rounded"
                    id="is_default"
                >
                <label for="is_default" class="text-sm font-medium text-gray-700">Set as Default Warehouse</label>
            </div>
        </div>

        <!-- Buttons -->
        <div class="flex gap-4 pt-6 border-t border-gray-200">
            <a href="{{ route('warehouses.index') }}" 
               class="flex-1 px-4 py-2 border border-gray-300 text-gray-700 rounded-lg hover:bg-gray-50 transition">
                Cancel
            </a>
            <button 
                type="submit"
                class="flex-1 px-4 py-2 bg-blue-600 text-white rounded-lg hover:bg-blue-700 transition font-medium">
                Create Warehouse
            </button>
        </div>
    </form>
</div>