<div class="space-y-6">

    {{-- Page Header --}}
    <div class="page-header">
        <div>
            <h1 class="page-title">Orders</h1>
            <p class="page-subtitle">All orders from your connected platforms</p>
        </div>
    </div>

    {{-- Revenue Card --}}
    <div class="rounded-2xl bg-gradient-to-br from-blue-600 to-violet-600 p-6 text-white">
        <div class="flex items-center justify-between">
            <div>
                <p class="text-sm font-medium text-blue-100">Total Revenue (Filtered)</p>
                <p class="text-3xl font-bold mt-1 tabular-nums">{{ $totalRevenue }} MAD</p>
            </div>
            <div class="w-12 h-12 bg-white/20 rounded-xl flex items-center justify-center flex-shrink-0">
                <svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="1.7" aria-hidden="true">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M12 8c-1.657 0-3 .895-3 2s1.343 2 3 2 3 .895 3 2-1.343 2-3 2m0-8c1.11 0 2.08.402 2.599 1M12 8V7m0 1v8m0 0v1m0-1c-1.11 0-2.08-.402-2.599-1M21 12a9 9 0 11-18 0 9 9 0 0118 0z"/>
                </svg>
            </div>
        </div>
    </div>

    {{-- Filters --}}
    <div class="filter-bar">
        <div class="grid grid-cols-1 md:grid-cols-5 gap-3">
            <div class="md:col-span-2 relative">
                <svg class="w-4 h-4 absolute left-3 top-3 text-gray-400 pointer-events-none" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="1.7" aria-hidden="true">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z"/>
                </svg>
                <input type="search"
                       wire:model.live.debounce.300ms="search"
                       placeholder="Search order or customer…"
                       aria-label="Search orders"
                       class="input pl-9">
            </div>

            <select wire:model.live="storeFilter" aria-label="Filter by store" class="input">
                <option value="">All Stores</option>
                @foreach($stores as $store)
                    <option value="{{ $store->id }}">{{ $store->name }}</option>
                @endforeach
            </select>

            <select wire:model.live="statusFilter" aria-label="Filter by status" class="input">
                <option value="">All Status</option>
                @foreach($orderStatuses as $status)
                    <option value="{{ $status->value }}">{{ $status->label() }}</option>
                @endforeach
            </select>

            <select wire:model.live="dateFilter" aria-label="Filter by date range" class="input">
                <option value="all">All Time</option>
                <option value="today">Today</option>
                <option value="week">Last 7 Days</option>
                <option value="month">Last 30 Days</option>
            </select>
        </div>
    </div>

    {{-- Orders Table --}}
    @if($orders->count() > 0)
        <div class="table-container">
            <div class="overflow-x-auto">
                <table class="w-full" aria-label="Orders table">
                    <thead>
                        <tr>
                            <th scope="col" class="table-th">Order</th>
                            <th scope="col" class="table-th">Customer</th>
                            <th scope="col" class="table-th">Store</th>
                            <th scope="col" class="table-th">Status</th>
                            <th scope="col" class="table-th">Total</th>
                            <th scope="col" class="table-th">Date</th>
                            <th scope="col" class="table-th text-right">
                                <span class="sr-only">Actions</span>
                            </th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach($orders as $order)
                            <tr class="table-row cursor-pointer"
                                wire:click="$navigate('{{ route('orders.show', $order) }}')">
                                <td class="table-td">
                                    <p class="font-medium text-gray-900 dark:text-white">{{ $order->order_number }}</p>
                                    <p class="text-xs text-gray-400 dark:text-gray-500 mt-0.5">
                                        {{ $order->platformConnection?->platform ?? '—' }}
                                    </p>
                                </td>
                                <td class="table-td">
                                    <p class="text-gray-900 dark:text-gray-100">{{ $order->customer_name }}</p>
                                    @if($order->customer_phone)
                                        <p class="text-xs text-gray-400 dark:text-gray-500 mt-0.5">{{ $order->customer_phone }}</p>
                                    @endif
                                </td>
                                <td class="table-td text-gray-600 dark:text-gray-300">
                                    {{ $order->store->name }}
                                </td>
                                <td class="table-td">
                                    <span class="badge
                                        @if($order->status === \App\Enums\OrderStatus::Pending)   badge-yellow
                                        @elseif($order->status === \App\Enums\OrderStatus::Confirmed) badge-green
                                        @elseif($order->status === \App\Enums\OrderStatus::Cancelled) badge-red
                                        @else badge-gray
                                        @endif">
                                        {{ $order->status->label() }}
                                    </span>
                                </td>
                                <td class="table-td font-semibold text-gray-900 dark:text-white tabular-nums">
                                    {{ number_format($order->total, 2) }} {{ $order->currency }}
                                </td>
                                <td class="table-td text-gray-500 dark:text-gray-400 text-xs">
                                    {{ $order->created_at->diffForHumans() }}
                                </td>
                                <td class="table-td text-right">
                                    @if($order->isPending())
                                        <div class="flex items-center justify-end gap-1" onclick="event.stopPropagation()">
                                            <button wire:click.stop="markAsConfirmed('{{ $order->id }}')"
                                                    class="btn-success text-xs px-2.5 py-1">
                                                Confirm
                                            </button>
                                            <button wire:click.stop="markAsCancelled('{{ $order->id }}')"
                                                    class="btn-danger text-xs px-2.5 py-1">
                                                Cancel
                                            </button>
                                        </div>
                                    @endif
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>

            {{-- Pagination --}}
            @if($orders->hasPages())
                <div class="px-5 py-4 border-t border-gray-100 dark:border-gray-700">
                    {{ $orders->links() }}
                </div>
            @endif
        </div>
    @else
        {{-- Empty State --}}
        <div class="card flex flex-col items-center justify-center py-16 text-center">
            <div class="w-14 h-14 bg-gray-100 dark:bg-gray-700 rounded-full flex items-center justify-center mb-4">
                <svg class="w-7 h-7 text-gray-400" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="1.5" aria-hidden="true">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M9 5H7a2 2 0 00-2 2v12a2 2 0 002 2h10a2 2 0 002-2V7a2 2 0 00-2-2h-2M9 5a2 2 0 002 2h2a2 2 0 002-2M9 5a2 2 0 012-2h2a2 2 0 012 2"/>
                </svg>
            </div>
            <h3 class="text-sm font-semibold text-gray-900 dark:text-white">No orders yet</h3>
            <p class="text-sm text-gray-500 dark:text-gray-400 mt-1 max-w-xs">
                Orders will appear here once synced from your connected platforms.
            </p>
        </div>
    @endif

</div>
