<div class="space-y-6 p-6 bg-white rounded-lg shadow max-w-2xl">
    <h2 class="text-2xl font-bold">WhatsApp Setup</h2>

    @if ($isConnected)
        <div class="bg-green-50 border border-green-200 p-4 rounded-lg">
            <h3 class="font-bold text-green-700">✅ Connected!</h3>
            <p class="text-sm text-green-600 mt-2">Phone: {{ $connectedPhone }}</p>
            <button 
                wire:click="disconnect"
                class="mt-3 text-sm text-red-600 hover:text-red-800"
            >
                Disconnect
            </button>
        </div>
    @else
        <button 
            wire:click="startOAuth"
            class="w-full bg-blue-600 text-white py-4 rounded-lg font-bold text-lg hover:bg-blue-700 transition"
        >
            📱 Connect WhatsApp with Facebook
        </button>
        <p class="text-center text-gray-600 text-sm">
            Login with your Facebook Business Account
        </p>
    @endif
</div>