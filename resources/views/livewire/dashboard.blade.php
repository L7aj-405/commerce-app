<div class="space-y-8">

    {{-- Stat Cards --}}
    <div class="grid grid-cols-1 md:grid-cols-3 gap-6">

        {{-- Total Sales --}}
        <div class="card flex flex-col gap-5">
            <div class="flex items-start justify-between">
                <div class="w-10 h-10 bg-blue-600 rounded-xl flex items-center justify-center flex-shrink-0">
                    <svg class="w-5 h-5 text-white" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="1.7" aria-hidden="true">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M12 8c-1.657 0-3 .895-3 2s1.343 2 3 2 3 .895 3 2-1.343 2-3 2m0-8c1.11 0 2.08.402 2.599 1M12 8V7m0 1v8m0 0v1m0-1c-1.11 0-2.08-.402-2.599-1M21 12a9 9 0 11-18 0 9 9 0 0118 0z"/>
                    </svg>
                </div>
                <span class="badge badge-green text-xs">
                    <svg class="w-3 h-3" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="2.5" aria-hidden="true">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M5 10l7-7m0 0l7 7m-7-7v18"/>
                    </svg>
                    {{ $stats['total_sales']['change'] }}
                </span>
            </div>
            <div>
                <p class="stat-label">Total Sales</p>
                <p class="stat-value mt-1">{{ $stats['total_sales']['value'] ? '$' . number_format($stats['total_sales']['value'], 2) : '$0.00' }}</p>
            </div>
            <div class="divider pt-1 flex items-center gap-2 text-xs text-gray-500 dark:text-gray-400">
                <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="1.7" aria-hidden="true">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M9 5H7a2 2 0 00-2 2v12a2 2 0 002 2h10a2 2 0 002-2V7a2 2 0 00-2-2h-2M9 5a2 2 0 002 2h2a2 2 0 002-2M9 5a2 2 0 012-2h2a2 2 0 012 2"/>
                </svg>
                {{ $stats['total_sales']['orders_count'] }} orders · {{ $stats['total_sales']['period'] }}
            </div>
        </div>

        {{-- Visitors --}}
        <div class="card flex flex-col gap-5">
            <div class="flex items-start justify-between">
                <div class="w-10 h-10 bg-violet-600 rounded-xl flex items-center justify-center flex-shrink-0">
                    <svg class="w-5 h-5 text-white" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="1.7" aria-hidden="true">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M12 4.354a4 4 0 110 5.292M15 21H3v-1a6 6 0 0112 0v1zm0 0h6v-1a6 6 0 00-9-5.197M13 7a4 4 0 11-8 0 4 4 0 018 0z"/>
                    </svg>
                </div>
                <span class="badge badge-green text-xs">
                    <svg class="w-3 h-3" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="2.5" aria-hidden="true">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M5 10l7-7m0 0l7 7m-7-7v18"/>
                    </svg>
                    {{ $stats['visitors']['change'] }}
                </span>
            </div>
            <div>
                <p class="stat-label">Visitors</p>
                <p class="stat-value mt-1">{{ number_format($stats['visitors']['value']) }}</p>
            </div>
            <div class="divider pt-1 flex items-center gap-2 text-xs text-gray-500 dark:text-gray-400">
                <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="1.7" aria-hidden="true">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M12 8v4l3 3m6-3a9 9 0 11-18 0 9 9 0 0118 0z"/>
                </svg>
                Avg. {{ $stats['visitors']['avg_time'] }} · {{ $stats['visitors']['period'] }}
            </div>
        </div>

        {{-- Refunds --}}
        <div class="card flex flex-col gap-5">
            <div class="flex items-start justify-between">
                <div class="w-10 h-10 bg-red-500 rounded-xl flex items-center justify-center flex-shrink-0">
                    <svg class="w-5 h-5 text-white" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="1.7" aria-hidden="true">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M3 10h10a8 8 0 018 8v2M3 10l6 6m-6-6l6-6"/>
                    </svg>
                </div>
                <span class="badge badge-red text-xs">
                    <svg class="w-3 h-3" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="2.5" aria-hidden="true">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M19 14l-7 7m0 0l-7-7m7 7V3"/>
                    </svg>
                    {{ $stats['refunds']['change'] }}
                </span>
            </div>
            <div>
                <p class="stat-label">Refunds</p>
                <p class="stat-value mt-1">{{ $stats['refunds']['value'] }}</p>
            </div>
            <div class="divider pt-1 flex items-center gap-2 text-xs text-gray-500 dark:text-gray-400">
                <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="1.7" aria-hidden="true">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-3L13.732 4c-.77-1.333-2.694-1.333-3.464 0L3.34 16c-.77 1.333.192 3 1.732 3z"/>
                </svg>
                {{ $stats['refunds']['disputed'] }} disputed · {{ $stats['refunds']['period'] }}
            </div>
        </div>

    </div>

    {{-- Charts Row --}}
    <div class="grid grid-cols-1 lg:grid-cols-3 gap-6">

        {{-- Sales Performance --}}
        <div class="card lg:col-span-2">
            <div class="flex items-center justify-between mb-6">
                <div>
                    <h2 class="section-title">Sales Performance</h2>
                    <p class="text-xs text-gray-400 dark:text-gray-500 mt-0.5">Revenue over the last 14 days</p>
                </div>
                <div class="flex items-center gap-2">
                    <button class="btn-secondary text-xs px-3 py-1.5">
                        <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="1.7" aria-hidden="true">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M4 16v1a3 3 0 003 3h10a3 3 0 003-3v-1m-4-4l-4 4m0 0l-4-4m4 4V4"/>
                        </svg>
                        Export
                    </button>
                    <button class="btn-secondary text-xs px-3 py-1.5">Last 14 days</button>
                </div>
            </div>
            <div class="h-56 bg-gray-50 dark:bg-gray-900/50 rounded-xl flex flex-col items-center justify-center gap-2 text-gray-400 dark:text-gray-600 border border-dashed border-gray-200 dark:border-gray-700">
                <svg class="w-8 h-8" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="1.2" aria-hidden="true">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M7 12l3-3 3 3 4-4M8 21l4-4 4 4M3 4h18M4 4h16v12a1 1 0 01-1 1H5a1 1 0 01-1-1V4z"/>
                </svg>
                <p class="text-sm font-medium">Chart coming soon</p>
            </div>
        </div>

        {{-- Top Categories --}}
        <div class="card">
            <div class="mb-6">
                <h2 class="section-title">Top Categories</h2>
                <p class="text-xs text-gray-400 dark:text-gray-500 mt-0.5">By revenue share</p>
            </div>
            <div class="h-40 bg-gray-50 dark:bg-gray-900/50 rounded-xl flex flex-col items-center justify-center gap-1 text-gray-400 dark:text-gray-600 border border-dashed border-gray-200 dark:border-gray-700 mb-6">
                <svg class="w-7 h-7" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="1.2" aria-hidden="true">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M11 3.055A9.001 9.001 0 1020.945 13H11V3.055z"/>
                    <path stroke-linecap="round" stroke-linejoin="round" d="M20.488 9H15V3.512A9.025 9.025 0 0120.488 9z"/>
                </svg>
                <p class="text-xs font-medium">Donut chart coming soon</p>
            </div>
            <div class="space-y-3">
                <div class="flex items-center justify-between">
                    <div class="flex items-center gap-2.5">
                        <span class="w-2.5 h-2.5 rounded-full bg-blue-600 flex-shrink-0"></span>
                        <span class="text-sm text-gray-700 dark:text-gray-300">Electronics</span>
                    </div>
                    <span class="text-sm font-semibold text-gray-900 dark:text-white">48%</span>
                </div>
                <div class="flex items-center justify-between">
                    <div class="flex items-center gap-2.5">
                        <span class="w-2.5 h-2.5 rounded-full bg-violet-500 flex-shrink-0"></span>
                        <span class="text-sm text-gray-700 dark:text-gray-300">Laptops</span>
                    </div>
                    <span class="text-sm font-semibold text-gray-900 dark:text-white">32%</span>
                </div>
                <div class="flex items-center justify-between">
                    <div class="flex items-center gap-2.5">
                        <span class="w-2.5 h-2.5 rounded-full bg-emerald-500 flex-shrink-0"></span>
                        <span class="text-sm text-gray-700 dark:text-gray-300">Phones</span>
                    </div>
                    <span class="text-sm font-semibold text-gray-900 dark:text-white">20%</span>
                </div>
            </div>
        </div>

    </div>

</div>
