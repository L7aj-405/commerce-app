<div class="space-y-6">

    {{-- Page Header --}}
    <div class="page-header">
        <div>
            <h1 class="page-title">Order {{ $order->order_number }}</h1>
            <p class="page-subtitle">Created {{ $order->created_at->format('M d, Y H:i') }}</p>
        </div>
        @if($order->isPending())
            <div class="flex items-center gap-2 flex-shrink-0">
                <button wire:click="sendWhatsApp" class="btn-secondary">
                    <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="1.7" aria-hidden="true">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M8 12h.01M12 12h.01M16 12h.01M21 12c0 4.418-4.03 8-9 8a9.863 9.863 0 01-4.255-.949L3 20l1.395-3.72C3.512 15.042 3 13.574 3 12c0-4.418 4.03-8 9-8s9 3.582 9 8z"/>
                    </svg>
                    WhatsApp
                </button>
                <button wire:click="markAsConfirmed" class="btn-success">
                    <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="2" aria-hidden="true">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M5 13l4 4L19 7"/>
                    </svg>
                    Confirm
                </button>
                <button wire:click="markAsCancelled" class="btn-danger">
                    <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="2" aria-hidden="true">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M6 18L18 6M6 6l12 12"/>
                    </svg>
                    Cancel
                </button>
            </div>
        @endif
    </div>

    {{-- Grid Layout --}}
    <div class="grid grid-cols-1 lg:grid-cols-3 gap-6">

        {{-- Left: Order Info --}}
        <div class="lg:col-span-2 space-y-6">

            {{-- Customer Card --}}
            <div class="card">
                <h3 class="section-title mb-5 flex items-center gap-2">
                    <svg class="w-5 h-5 text-gray-400" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="1.7" aria-hidden="true">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M16 7a4 4 0 11-8 0 4 4 0 018 0zM12 14a7 7 0 00-7 7h14a7 7 0 00-7-7z"/>
                    </svg>
                    Customer Information
                </h3>
                <dl class="grid grid-cols-1 sm:grid-cols-3 gap-4">
                    <div>
                        <dt class="text-xs font-medium text-gray-500 dark:text-gray-400 uppercase tracking-wider">Name</dt>
                        <dd class="mt-1 text-sm font-medium text-gray-900 dark:text-white">{{ $order->customer_name }}</dd>
                    </div>
                    <div>
                        <dt class="text-xs font-medium text-gray-500 dark:text-gray-400 uppercase tracking-wider">Email</dt>
                        <dd class="mt-1 text-sm text-gray-700 dark:text-gray-300">{{ $order->customer_email ?? '—' }}</dd>
                    </div>
                    <div>
                        <dt class="text-xs font-medium text-gray-500 dark:text-gray-400 uppercase tracking-wider">Phone</dt>
                        <dd class="mt-1 text-sm text-gray-700 dark:text-gray-300">{{ $order->customer_phone ?? '—' }}</dd>
                    </div>
                </dl>
            </div>

            {{-- Items Table --}}
            <div class="card">
                <h3 class="section-title mb-5 flex items-center gap-2">
                    <svg class="w-5 h-5 text-gray-400" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="1.7" aria-hidden="true">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M9 5H7a2 2 0 00-2 2v12a2 2 0 002 2h10a2 2 0 002-2V7a2 2 0 00-2-2h-2M9 5a2 2 0 002 2h2a2 2 0 002-2M9 5a2 2 0 012-2h2a2 2 0 012 2"/>
                    </svg>
                    Order Items
                </h3>
                <div class="overflow-x-auto -mx-6">
                    <table class="w-full" aria-label="Order items">
                        <thead>
                            <tr class="border-b border-gray-100 dark:border-gray-700">
                                <th scope="col" class="px-6 py-3 text-left text-xs font-semibold text-gray-500 dark:text-gray-400 uppercase tracking-wider">Product</th>
                                <th scope="col" class="px-6 py-3 text-center text-xs font-semibold text-gray-500 dark:text-gray-400 uppercase tracking-wider">Qty</th>
                                <th scope="col" class="px-6 py-3 text-right text-xs font-semibold text-gray-500 dark:text-gray-400 uppercase tracking-wider">Unit Price</th>
                                <th scope="col" class="px-6 py-3 text-right text-xs font-semibold text-gray-500 dark:text-gray-400 uppercase tracking-wider">Total</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-gray-100 dark:divide-gray-700">
                            @foreach($order->items as $item)
                                <tr>
                                    <td class="px-6 py-3.5 text-sm text-gray-900 dark:text-gray-100">{{ $item['name'] }}</td>
                                    <td class="px-6 py-3.5 text-sm text-center text-gray-600 dark:text-gray-300 tabular-nums">{{ $item['quantity'] }}</td>
                                    <td class="px-6 py-3.5 text-sm text-right text-gray-600 dark:text-gray-300 tabular-nums">{{ number_format($item['price'], 2) }}</td>
                                    <td class="px-6 py-3.5 text-sm text-right font-medium text-gray-900 dark:text-white tabular-nums">{{ number_format($item['quantity'] * $item['price'], 2) }}</td>
                                </tr>
                            @endforeach
                        </tbody>
                        <tfoot>
                            <tr class="border-t-2 border-gray-200 dark:border-gray-600">
                                <td colspan="3" class="px-6 py-4 text-sm font-semibold text-right text-gray-900 dark:text-white">Order Total</td>
                                <td class="px-6 py-4 text-base font-bold text-right text-gray-900 dark:text-white tabular-nums">
                                    {{ number_format($order->total, 2) }} {{ $order->currency }}
                                </td>
                            </tr>
                        </tfoot>
                    </table>
                </div>
            </div>

            {{-- Platform Data (Collapsible) --}}
            <div class="card" x-data="{ open: false }">
                <button @click="open = !open"
                        class="w-full flex items-center justify-between gap-3 text-left focus:outline-none focus-visible:ring-2 focus-visible:ring-blue-500 rounded-lg"
                        :aria-expanded="open">
                    <span class="section-title flex items-center gap-2">
                        <svg class="w-5 h-5 text-gray-400" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="1.7" aria-hidden="true">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M10 20l4-16m4 4l4 4-4 4M6 16l-4-4 4-4"/>
                        </svg>
                        Platform Data
                    </span>
                    <svg class="w-4 h-4 text-gray-400 transition-transform duration-200 flex-shrink-0"
                         :class="{ 'rotate-180': open }"
                         fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="2" aria-hidden="true">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M19 9l-7 7-7-7"/>
                    </svg>
                </button>
                <div x-show="open" x-cloak
                     x-transition:enter="transition ease-out duration-150"
                     x-transition:enter-start="opacity-0 -translate-y-1"
                     x-transition:enter-end="opacity-100 translate-y-0"
                     class="mt-4">
                    <pre class="bg-gray-50 dark:bg-gray-900/60 border border-gray-200 dark:border-gray-700 rounded-xl p-4 overflow-auto text-xs text-gray-700 dark:text-gray-300 font-mono leading-relaxed">{{ json_encode($order->platform_data, JSON_PRETTY_PRINT) }}</pre>
                </div>
            </div>

        </div>

        {{-- Right: Status & Timeline --}}
        <div class="space-y-6">

            {{-- Status Card --}}
            <div class="card">
                <h3 class="section-title mb-5 flex items-center gap-2">
                    <svg class="w-5 h-5 text-gray-400" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="1.7" aria-hidden="true">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z"/>
                    </svg>
                    Order Status
                </h3>
                <div class="flex items-center justify-center py-2">
                    <span class="badge text-sm py-1.5 px-4
                        @if($order->status === \App\Enums\OrderStatus::Pending)   badge-yellow
                        @elseif($order->status === \App\Enums\OrderStatus::Confirmed) badge-green
                        @elseif($order->status === \App\Enums\OrderStatus::Cancelled) badge-red
                        @else badge-gray
                        @endif">
                        {{ $order->status->label() }}
                    </span>
                </div>
            </div>

            {{-- Timeline --}}
            <div class="card">
                <h3 class="section-title mb-5 flex items-center gap-2">
                    <svg class="w-5 h-5 text-gray-400" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="1.7" aria-hidden="true">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M12 8v4l3 3m6-3a9 9 0 11-18 0 9 9 0 0118 0z"/>
                    </svg>
                    Timeline
                </h3>
                <div class="space-y-4">

                    {{-- Order Created --}}
                    <div class="flex gap-3">
                        <div class="timeline-dot w-8 h-8 bg-blue-100 dark:bg-blue-500/20 flex-shrink-0">
                            <svg class="w-4 h-4 text-blue-600 dark:text-blue-400" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="1.7" aria-hidden="true">
                                <path stroke-linecap="round" stroke-linejoin="round" d="M20 7l-8-4-8 4m16 0l-8 4m8-4v10l-8 4m0-10L4 7m8 4v10M4 7v10l8 4"/>
                            </svg>
                        </div>
                        <div class="pt-0.5">
                            <p class="text-sm font-medium text-gray-900 dark:text-white">Order Created</p>
                            <p class="text-xs text-gray-500 dark:text-gray-400 mt-0.5">{{ $order->created_at->format('M d, Y H:i') }}</p>
                        </div>
                    </div>

                    @if($order->whatsapp_sent_at)
                        <div class="flex gap-3">
                            <div class="timeline-dot w-8 h-8 bg-emerald-100 dark:bg-emerald-500/20 flex-shrink-0">
                                <svg class="w-4 h-4 text-emerald-600 dark:text-emerald-400" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="1.7" aria-hidden="true">
                                    <path stroke-linecap="round" stroke-linejoin="round" d="M8 12h.01M12 12h.01M16 12h.01M21 12c0 4.418-4.03 8-9 8a9.863 9.863 0 01-4.255-.949L3 20l1.395-3.72C3.512 15.042 3 13.574 3 12c0-4.418 4.03-8 9-8s9 3.582 9 8z"/>
                                </svg>
                            </div>
                            <div class="pt-0.5">
                                <p class="text-sm font-medium text-gray-900 dark:text-white">WhatsApp Sent</p>
                                <p class="text-xs text-gray-500 dark:text-gray-400 mt-0.5">{{ $order->whatsapp_sent_at->format('M d, Y H:i') }}</p>
                            </div>
                        </div>
                    @endif

                    @if($order->whatsapp_confirmed_at)
                        <div class="flex gap-3">
                            <div class="timeline-dot w-8 h-8 bg-emerald-100 dark:bg-emerald-500/20 flex-shrink-0">
                                <svg class="w-4 h-4 text-emerald-600 dark:text-emerald-400" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="1.7" aria-hidden="true">
                                    <path stroke-linecap="round" stroke-linejoin="round" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z"/>
                                </svg>
                            </div>
                            <div class="pt-0.5">
                                <p class="text-sm font-medium text-gray-900 dark:text-white">Customer Confirmed</p>
                                <p class="text-xs text-gray-500 dark:text-gray-400 mt-0.5">{{ $order->whatsapp_confirmed_at->format('M d, Y H:i') }}</p>
                            </div>
                        </div>
                    @endif

                </div>
            </div>

        </div>

    </div>

</div>
