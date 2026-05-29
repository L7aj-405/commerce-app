<div
    x-data="{}"
    @sync-tick.window="$wire.processNext()"
    @sync-completed.window="$wire.dispatch('notify', { message: 'Products synced successfully!', type: 'success' })"
>
    {{-- Trigger Button --}}
    <button
        wire:click="openModal"
        class="inline-flex items-center gap-2 px-4 py-2 bg-violet-600 text-white rounded-lg hover:bg-violet-700 transition text-sm font-medium"
    >
        <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                  d="M4 4v5h.582m15.356 2A8.001 8.001 0 004.582 9m0 0H9m11 11v-5h-.581m0 0a8.003 8.003 0 01-15.357-2m15.357 2H15"/>
        </svg>
        Sync All Products
    </button>

    {{-- Modal Backdrop --}}
    @if($showModal)
        <div class="fixed inset-0 z-50 flex items-center justify-center">
            {{-- Backdrop --}}
            <div
                class="absolute inset-0 bg-gray-900/60 backdrop-blur-sm"
                wire:click="closeModal"
            ></div>

            {{-- Modal Panel --}}
            <div class="relative bg-white rounded-2xl shadow-2xl w-full max-w-lg mx-4 overflow-hidden">

                {{-- Header --}}
                <div class="px-6 py-5 border-b border-gray-100 flex items-center justify-between">
                    <div>
                        <h2 class="text-lg font-semibold text-gray-900">Sync Products from Platforms</h2>
                        <p class="text-sm text-gray-500 mt-0.5">Pull latest products into your catalogue</p>
                    </div>
                    @if(!$syncInProgress)
                        <button
                            wire:click="closeModal"
                            class="text-gray-400 hover:text-gray-600 transition p-1 rounded-lg hover:bg-gray-100"
                        >
                            <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"/>
                            </svg>
                        </button>
                    @endif
                </div>

                {{-- Body --}}
                <div class="px-6 py-5 space-y-5">

                    @if(!$syncInProgress && !$syncDone)
                        {{-- ── BEFORE STATE ── --}}

                        {{-- Platform Selection --}}
                        <div>
                            <p class="text-sm font-medium text-gray-700 mb-3">Select platforms to sync</p>

                            @if($connections->isEmpty())
                                <div class="rounded-xl bg-amber-50 border border-amber-200 px-4 py-3 text-sm text-amber-800">
                                    No active platform connections found.
                                    <a href="{{ route('stores.connections.index', $store) }}" class="font-medium underline">Connect a platform</a>
                                </div>
                            @else
                                <div class="space-y-2">
                                    @foreach($connections as $conn)
                                        <label class="flex items-center justify-between p-3 rounded-xl border border-gray-200 hover:border-violet-300 hover:bg-violet-50/50 cursor-pointer transition group">
                                            <div class="flex items-center gap-3">
                                                <input
                                                    type="checkbox"
                                                    wire:model="selectedConnectionIds"
                                                    value="{{ $conn['id'] }}"
                                                    class="w-4 h-4 text-violet-600 rounded border-gray-300 focus:ring-violet-500"
                                                >
                                                <div>
                                                    <span class="text-sm font-medium text-gray-900">{{ $conn['label'] }}</span>
                                                    <span class="ml-2 px-2 py-0.5 rounded-full text-xs font-semibold
                                                        {{ $conn['platform'] === 'shopify' ? 'bg-green-100 text-green-700' : ($conn['platform'] === 'woocommerce' ? 'bg-purple-100 text-purple-700' : 'bg-blue-100 text-blue-700') }}">
                                                        {{ ucfirst($conn['platform']) }}
                                                    </span>
                                                </div>
                                            </div>
                                            <div class="text-right">
                                                <span class="text-sm text-gray-500">{{ number_format($conn['product_count']) }} products</span>
                                                @if($conn['last_sync'])
                                                    <p class="text-xs text-gray-400">synced {{ $conn['last_sync'] }}</p>
                                                @else
                                                    <p class="text-xs text-gray-400">never synced</p>
                                                @endif
                                            </div>
                                        </label>
                                    @endforeach
                                </div>
                            @endif
                        </div>

                        {{-- Sync Options --}}
                        <div class="rounded-xl bg-gray-50 border border-gray-200 p-4 space-y-3">
                            <p class="text-xs font-semibold text-gray-500 uppercase tracking-wide">Sync options</p>
                            <label class="flex items-center gap-3 cursor-pointer">
                                <input type="checkbox" wire:model="doSyncProducts"
                                       class="w-4 h-4 text-violet-600 rounded border-gray-300 focus:ring-violet-500">
                                <div>
                                    <span class="text-sm font-medium text-gray-800">Products</span>
                                    <span class="text-xs text-gray-500 ml-1">— name, price, description, SKU</span>
                                </div>
                            </label>
                            <label class="flex items-center gap-3 cursor-pointer">
                                <input type="checkbox" wire:model="doSyncVariants"
                                       class="w-4 h-4 text-violet-600 rounded border-gray-300 focus:ring-violet-500">
                                <div>
                                    <span class="text-sm font-medium text-gray-800">Variants</span>
                                    <span class="text-xs text-gray-500 ml-1">— sizes, colours, options</span>
                                </div>
                            </label>
                            <label class="flex items-center gap-3 cursor-pointer">
                                <input type="checkbox" wire:model="doSyncStock"
                                       class="w-4 h-4 text-violet-600 rounded border-gray-300 focus:ring-violet-500">
                                <div>
                                    <span class="text-sm font-medium text-gray-800">Stock levels</span>
                                    <span class="text-xs text-gray-500 ml-1">— overwrite local quantities</span>
                                </div>
                            </label>
                        </div>

                    @elseif($syncInProgress)
                        {{-- ── DURING STATE ── --}}

                        <div class="space-y-4">
                            {{-- Status message --}}
                            <div class="flex items-center gap-3">
                                <div class="w-5 h-5 border-2 border-violet-600 border-t-transparent rounded-full animate-spin flex-shrink-0"></div>
                                <p class="text-sm font-medium text-gray-800">{{ $syncStatus }}</p>
                            </div>

                            {{-- Progress bar --}}
                            <div>
                                <div class="flex justify-between text-xs text-gray-500 mb-1.5">
                                    <span>Progress</span>
                                    <span>{{ $syncProgress }}%</span>
                                </div>
                                <div class="w-full bg-gray-100 rounded-full h-2.5 overflow-hidden">
                                    <div
                                        class="bg-gradient-to-r from-violet-500 to-violet-600 h-2.5 rounded-full transition-all duration-500 ease-out"
                                        style="width: {{ $syncProgress }}%"
                                    ></div>
                                </div>
                            </div>

                            {{-- Elapsed time --}}
                            <p class="text-xs text-gray-400 text-right">
                                Elapsed: <span wire:poll.500ms="getElapsedTime">—</span>
                            </p>

                            {{-- Partial results --}}
                            @if(!empty($syncResults))
                                <div class="rounded-xl border border-gray-100 overflow-hidden">
                                    <table class="w-full text-sm">
                                        <thead class="bg-gray-50">
                                            <tr>
                                                <th class="px-4 py-2 text-left text-xs font-semibold text-gray-500">Platform</th>
                                                <th class="px-4 py-2 text-right text-xs font-semibold text-gray-500">New</th>
                                                <th class="px-4 py-2 text-right text-xs font-semibold text-gray-500">Updated</th>
                                                <th class="px-4 py-2 text-right text-xs font-semibold text-gray-500">Failed</th>
                                            </tr>
                                        </thead>
                                        <tbody class="divide-y divide-gray-50">
                                            @foreach($syncResults as $result)
                                                <tr>
                                                    <td class="px-4 py-2.5 text-gray-800 font-medium">
                                                        @if($result['error'])
                                                            <span class="text-red-500">✗</span>
                                                        @else
                                                            <span class="text-green-500">✓</span>
                                                        @endif
                                                        {{ $result['label'] }}
                                                    </td>
                                                    <td class="px-4 py-2.5 text-right text-gray-600">{{ $result['created'] }}</td>
                                                    <td class="px-4 py-2.5 text-right text-gray-600">{{ $result['updated'] }}</td>
                                                    <td class="px-4 py-2.5 text-right {{ $result['failed'] > 0 ? 'text-red-600 font-medium' : 'text-gray-600' }}">{{ $result['failed'] }}</td>
                                                </tr>
                                            @endforeach
                                        </tbody>
                                    </table>
                                </div>
                            @endif
                        </div>

                    @else
                        {{-- ── DONE STATE ── --}}

                        @php $summary = $this->getSyncSummary(); @endphp

                        <div class="space-y-4">
                            {{-- Success header --}}
                            <div class="flex items-center gap-3 p-4 bg-green-50 border border-green-200 rounded-xl">
                                <div class="w-8 h-8 bg-green-100 rounded-full flex items-center justify-center flex-shrink-0">
                                    <svg class="w-4 h-4 text-green-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.5" d="M5 13l4 4L19 7"/>
                                    </svg>
                                </div>
                                <div>
                                    <p class="text-sm font-semibold text-green-800">Sync Complete</p>
                                    <p class="text-xs text-green-600">Finished in {{ $this->getElapsedTime() }}</p>
                                </div>
                            </div>

                            {{-- Summary totals --}}
                            <div class="grid grid-cols-3 gap-3">
                                <div class="text-center p-3 bg-blue-50 rounded-xl">
                                    <p class="text-2xl font-bold text-blue-700">{{ $summary['created'] }}</p>
                                    <p class="text-xs text-blue-600 mt-0.5">New</p>
                                </div>
                                <div class="text-center p-3 bg-amber-50 rounded-xl">
                                    <p class="text-2xl font-bold text-amber-700">{{ $summary['updated'] }}</p>
                                    <p class="text-xs text-amber-600 mt-0.5">Updated</p>
                                </div>
                                <div class="text-center p-3 {{ $summary['failed'] > 0 ? 'bg-red-50' : 'bg-gray-50' }} rounded-xl">
                                    <p class="text-2xl font-bold {{ $summary['failed'] > 0 ? 'text-red-700' : 'text-gray-400' }}">{{ $summary['failed'] }}</p>
                                    <p class="text-xs {{ $summary['failed'] > 0 ? 'text-red-600' : 'text-gray-400' }} mt-0.5">Failed</p>
                                </div>
                            </div>

                            {{-- Per-platform breakdown --}}
                            @if(!empty($syncResults))
                                <div class="rounded-xl border border-gray-200 overflow-hidden">
                                    <table class="w-full text-sm">
                                        <thead class="bg-gray-50 border-b border-gray-100">
                                            <tr>
                                                <th class="px-4 py-2.5 text-left text-xs font-semibold text-gray-500">Platform</th>
                                                <th class="px-4 py-2.5 text-right text-xs font-semibold text-gray-500">New</th>
                                                <th class="px-4 py-2.5 text-right text-xs font-semibold text-gray-500">Updated</th>
                                                <th class="px-4 py-2.5 text-right text-xs font-semibold text-gray-500">Failed</th>
                                            </tr>
                                        </thead>
                                        <tbody class="divide-y divide-gray-50">
                                            @foreach($syncResults as $result)
                                                <tr class="{{ $result['error'] ? 'bg-red-50/50' : '' }}">
                                                    <td class="px-4 py-3 text-gray-800 font-medium">
                                                        <div class="flex items-center gap-2">
                                                            @if($result['error'])
                                                                <span class="w-5 h-5 bg-red-100 rounded-full flex items-center justify-center flex-shrink-0">
                                                                    <svg class="w-3 h-3 text-red-500" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.5" d="M6 18L18 6M6 6l12 12"/>
                                                                    </svg>
                                                                </span>
                                                            @else
                                                                <span class="w-5 h-5 bg-green-100 rounded-full flex items-center justify-center flex-shrink-0">
                                                                    <svg class="w-3 h-3 text-green-500" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.5" d="M5 13l4 4L19 7"/>
                                                                    </svg>
                                                                </span>
                                                            @endif
                                                            {{ $result['label'] }}
                                                        </div>
                                                        @if($result['error'])
                                                            <p class="text-xs text-red-500 mt-1 ml-7">{{ Str::limit($result['error'], 60) }}</p>
                                                        @endif
                                                    </td>
                                                    <td class="px-4 py-3 text-right text-gray-600">{{ $result['created'] }}</td>
                                                    <td class="px-4 py-3 text-right text-gray-600">{{ $result['updated'] }}</td>
                                                    <td class="px-4 py-3 text-right {{ $result['failed'] > 0 ? 'text-red-600 font-semibold' : 'text-gray-600' }}">{{ $result['failed'] }}</td>
                                                </tr>
                                            @endforeach
                                        </tbody>
                                    </table>
                                </div>
                            @endif
                        </div>
                    @endif

                </div>

                {{-- Footer --}}
                <div class="px-6 py-4 bg-gray-50 border-t border-gray-100 flex items-center justify-between gap-3">
                    @if(!$syncInProgress && !$syncDone)
                        <button
                            wire:click="closeModal"
                            class="px-4 py-2 text-sm font-medium text-gray-600 hover:text-gray-800 hover:bg-gray-100 rounded-lg transition"
                        >
                            Cancel
                        </button>
                        <button
                            wire:click="startSync"
                            wire:loading.attr="disabled"
                            wire:target="startSync"
                            class="px-5 py-2 bg-violet-600 text-white text-sm font-medium rounded-lg hover:bg-violet-700 transition disabled:opacity-50 disabled:cursor-not-allowed flex items-center gap-2"
                        >
                            <span wire:loading.remove wire:target="startSync">Start Sync</span>
                            <span wire:loading wire:target="startSync" class="flex items-center gap-2">
                                <svg class="w-4 h-4 animate-spin" fill="none" viewBox="0 0 24 24">
                                    <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
                                    <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4z"></path>
                                </svg>
                                Starting…
                            </span>
                        </button>

                    @elseif($syncInProgress)
                        <p class="text-xs text-gray-400">Sync in progress — please wait…</p>
                        <button
                            disabled
                            class="px-5 py-2 bg-violet-300 text-white text-sm font-medium rounded-lg cursor-not-allowed flex items-center gap-2"
                        >
                            <svg class="w-4 h-4 animate-spin" fill="none" viewBox="0 0 24 24">
                                <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
                                <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4z"></path>
                            </svg>
                            Syncing…
                        </button>

                    @else
                        <button
                            wire:click="openModal"
                            class="px-4 py-2 text-sm font-medium text-gray-600 hover:text-gray-800 hover:bg-gray-100 rounded-lg transition"
                        >
                            Sync Again
                        </button>
                        <button
                            wire:click="closeModal"
                            class="px-5 py-2 bg-violet-600 text-white text-sm font-medium rounded-lg hover:bg-violet-700 transition"
                        >
                            Done
                        </button>
                    @endif
                </div>

            </div>
        </div>
    @endif
</div>
