@php
    $activeStore = Auth::user()->stores()->first();

    // Helper: build a nav-item class string
    $nav = fn (string|bool $active): string => implode(' ', [
        'group flex items-center gap-3 px-3 py-2.5 rounded-lg text-sm font-medium transition-all duration-150',
        $active
            ? 'bg-indigo-600/15 text-indigo-400'
            : 'text-gray-400 hover:text-gray-100 hover:bg-white/5',
    ]);
@endphp

<aside class="fixed inset-y-0 left-0 w-64 bg-gray-900 flex flex-col z-40 border-r border-gray-800"
       aria-label="Sidebar navigation">

    {{-- ── Logo ────────────────────────────────────────────── --}}
    <div class="h-16 flex items-center gap-3 px-5 border-b border-gray-800 flex-shrink-0">
        <div class="w-8 h-8 bg-indigo-600 rounded-lg flex items-center justify-center flex-shrink-0">
            <svg class="w-4 h-4 text-white" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="1.7" aria-hidden="true">
                <path stroke-linecap="round" stroke-linejoin="round" d="M20 7l-8-4-8 4m16 0l-8 4m8-4v10l-8 4m0-10L4 7m8 4v10M4 7v10l8 4"/>
            </svg>
        </div>
        <div class="min-w-0">
            <span class="text-white font-semibold text-sm tracking-tight">Commerce SaaS</span>
            @if($activeStore)
                <p class="text-[10px] text-gray-500 leading-none mt-0.5 truncate">{{ $activeStore->name }}</p>
            @endif
        </div>
    </div>

    {{-- ── Nav ─────────────────────────────────────────────── --}}
    <nav class="flex-1 px-3 py-4 overflow-y-auto" aria-label="Main navigation">

        {{-- OVERVIEW --}}
        <div class="mb-5">
            <p class="px-3 pb-2 text-[10px] font-semibold text-gray-500 uppercase tracking-widest">Overview</p>

            <a href="{{ route('dashboard') }}" wire:navigate
               class="{{ $nav(request()->routeIs('dashboard')) }}"
               aria-current="{{ request()->routeIs('dashboard') ? 'page' : 'false' }}">
                <svg class="w-5 h-5 flex-shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="1.7" aria-hidden="true">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M3 12l2-2m0 0l7-7 7 7M5 10v10a1 1 0 001 1h3m10-11l2 2m-2-2v10a1 1 0 01-1 1h-3m-6 0a1 1 0 001-1v-4a1 1 0 011-1h2a1 1 0 011 1v4a1 1 0 001 1m-6 0h6"/>
                </svg>
                Dashboard
            </a>
        </div>

        {{-- SALES --}}
        <div class="mb-5">
            <p class="px-3 pb-2 text-[10px] font-semibold text-gray-500 uppercase tracking-widest">Sales</p>

            <a href="{{ route('orders.index') }}" wire:navigate
               class="{{ $nav(request()->routeIs('orders.*')) }}"
               aria-current="{{ request()->routeIs('orders.*') ? 'page' : 'false' }}">
                <svg class="w-5 h-5 flex-shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="1.7" aria-hidden="true">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M9 5H7a2 2 0 00-2 2v12a2 2 0 002 2h10a2 2 0 002-2V7a2 2 0 00-2-2h-2M9 5a2 2 0 002 2h2a2 2 0 002-2M9 5a2 2 0 012-2h2a2 2 0 012 2m-3 7h3m-3 4h3m-6-4h.01M9 16h.01"/>
                </svg>
                Orders
            </a>

            <span class="{{ $nav(false) }} cursor-default select-none" aria-disabled="true">
                <svg class="w-5 h-5 flex-shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="1.7" aria-hidden="true">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M12 4.354a4 4 0 110 5.292M15 21H3v-1a6 6 0 0112 0v1zm0 0h6v-1a6 6 0 00-9-5.197M13 7a4 4 0 11-8 0 4 4 0 018 0z"/>
                </svg>
                <span class="flex-1">Customers</span>
                <span class="text-[10px] font-medium bg-gray-800 text-gray-500 px-1.5 py-0.5 rounded">Soon</span>
            </span>
        </div>

        {{-- CATALOG --}}
        <div class="mb-5">
            <p class="px-3 pb-2 text-[10px] font-semibold text-gray-500 uppercase tracking-widest">Catalog</p>

            @if($activeStore)
                <a href="{{ route('stores.products.index', $activeStore) }}" wire:navigate
                   class="{{ $nav(request()->routeIs('stores.products.*')) }}"
                   aria-current="{{ request()->routeIs('stores.products.*') ? 'page' : 'false' }}">
                    <svg class="w-5 h-5 flex-shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="1.7" aria-hidden="true">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M20 7l-8-4-8 4m16 0l-8 4m8-4v10l-8 4m0-10L4 7m8 4v10M4 7v10l8 4"/>
                    </svg>
                    Products
                </a>
            @else
                <a href="{{ route('stores.create') }}" wire:navigate
                   class="{{ $nav(false) }}">
                    <svg class="w-5 h-5 flex-shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="1.7" aria-hidden="true">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M20 7l-8-4-8 4m16 0l-8 4m8-4v10l-8 4m0-10L4 7m8 4v10M4 7v10l8 4"/>
                    </svg>
                    <span class="flex-1">Products</span>
                    <span class="text-[10px] font-medium bg-gray-800 text-gray-500 px-1.5 py-0.5 rounded">Add store</span>
                </a>
            @endif

            <a href="{{ route('warehouses.index') }}" wire:navigate
               class="{{ $nav(request()->routeIs('warehouses.*')) }}"
               aria-current="{{ request()->routeIs('warehouses.*') ? 'page' : 'false' }}">
                <svg class="w-5 h-5 flex-shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="1.7" aria-hidden="true">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M19 21V5a2 2 0 00-2-2H7a2 2 0 00-2 2v16m14 0h2m-2 0h-5m-9 0H3m2 0h5M9 7h1m-1 4h1m4-4h1m-1 4h1m-5 10v-5a1 1 0 011-1h2a1 1 0 011 1v5m-4 0h4"/>
                </svg>
                Warehouses
            </a>
        </div>

        {{-- STORES --}}
        <div class="mb-5" x-data="{ open: {{ request()->routeIs('stores.*') ? 'true' : 'false' }} }">
            <p class="px-3 pb-2 text-[10px] font-semibold text-gray-500 uppercase tracking-widest">Stores</p>

            <button @click="open = !open"
                    class="{{ $nav(request()->routeIs('stores.*')) }} w-full"
                    :aria-expanded="open">
                <svg class="w-5 h-5 flex-shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="1.7" aria-hidden="true">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M19 21V5a2 2 0 00-2-2H7a2 2 0 00-2 2v16m14 0h2m-2 0h-5m-9 0H3m2 0h5M9 7h1m-1 4h1m4-4h1m-1 4h1m-5 10v-5a1 1 0 011-1h2a1 1 0 011 1v5m-4 0h4"/>
                </svg>
                <span class="flex-1 text-left">My Stores</span>
                <svg class="w-3.5 h-3.5 opacity-50 transition-transform duration-200 flex-shrink-0"
                     :class="{ 'rotate-180': open }"
                     fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="2" aria-hidden="true">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M19 9l-7 7-7-7"/>
                </svg>
            </button>

            <div x-show="open" x-cloak
                 x-transition:enter="transition ease-out duration-150"
                 x-transition:enter-start="opacity-0 -translate-y-1"
                 x-transition:enter-end="opacity-100 translate-y-0"
                 x-transition:leave="transition ease-in duration-100"
                 x-transition:leave-start="opacity-100 translate-y-0"
                 x-transition:leave-end="opacity-0 -translate-y-1"
                 class="mt-0.5 ml-8 space-y-0.5 border-l border-gray-700/60 pl-3">

                <a href="{{ route('stores.index') }}" wire:navigate
                   class="flex items-center px-2 py-1.5 rounded-md text-sm transition-all duration-150
                          {{ request()->routeIs('stores.index') || request()->routeIs('stores.create') ? 'text-indigo-400' : 'text-gray-500 hover:text-gray-200 hover:bg-white/5' }}">
                    All Stores
                </a>

                @if($activeStore)
                    <a href="{{ route('dashboard.integrations.index') }}"
                       class="flex items-center px-2 py-1.5 rounded-md text-sm transition-all duration-150
                              {{ request()->routeIs('dashboard.integrations.*') ? 'text-indigo-400' : 'text-gray-500 hover:text-gray-200 hover:bg-white/5' }}">
                        Integrations
                    </a>

                    <a href="{{ route('dashboard.integrations.whatsapp') }}"
                       class="flex items-center px-2 py-1.5 rounded-md text-sm transition-all duration-150
                              {{ request()->routeIs('dashboard.integrations.whatsapp') ? 'text-indigo-400' : 'text-gray-500 hover:text-gray-200 hover:bg-white/5' }}">
                        WhatsApp
                    </a>

                    <a href="{{ route('stores.whatsapp.setup', $activeStore) }}" wire:navigate
                       class="flex items-center gap-1.5 px-2 py-1.5 rounded-md text-sm transition-all duration-150
                              {{ request()->routeIs('stores.whatsapp.*') ? 'text-indigo-400' : 'text-gray-500 hover:text-gray-200 hover:bg-white/5' }}">
                        <svg class="w-3 h-3 flex-shrink-0 opacity-70" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="1.7">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M13 10V3L4 14h7v7l9-11h-7z"/>
                        </svg>
                        Connect WhatsApp
                    </a>
                @endif
            </div>
        </div>

        {{-- AUTOMATION (Coming Soon) --}}
        <div class="mb-5">
            <p class="px-3 pb-2 text-[10px] font-semibold text-gray-500 uppercase tracking-widest">Automation</p>

            <span class="{{ $nav(false) }} cursor-default select-none" aria-disabled="true">
                <svg class="w-5 h-5 flex-shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="1.7" aria-hidden="true">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M13 10V3L4 14h7v7l9-11h-7z"/>
                </svg>
                <span class="flex-1">Order Automation</span>
                <span class="text-[10px] font-medium bg-gray-800 text-gray-500 px-1.5 py-0.5 rounded">Soon</span>
            </span>

            <span class="{{ $nav(false) }} cursor-default select-none" aria-disabled="true">
                <svg class="w-5 h-5 flex-shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="1.7" aria-hidden="true">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M9 19v-6a2 2 0 00-2-2H5a2 2 0 00-2 2v6a2 2 0 002 2h2a2 2 0 002-2zm0 0V9a2 2 0 012-2h2a2 2 0 012 2v10m-6 0a2 2 0 002 2h2a2 2 0 002-2m0 0V5a2 2 0 012-2h2a2 2 0 012 2v14a2 2 0 01-2 2h-2a2 2 0 01-2-2z"/>
                </svg>
                <span class="flex-1">Reports</span>
                <span class="text-[10px] font-medium bg-gray-800 text-gray-500 px-1.5 py-0.5 rounded">Soon</span>
            </span>
        </div>

    </nav>

    {{-- ── Bottom: User Profile ─────────────────────────────── --}}
    <div class="border-t border-gray-800 flex-shrink-0" x-data="{ profileOpen: false }">

        <button @click="profileOpen = !profileOpen"
                class="w-full flex items-center gap-3 px-4 py-3.5 hover:bg-white/5 transition-all duration-150 group"
                :aria-expanded="profileOpen"
                aria-haspopup="true">
            <div class="w-8 h-8 rounded-full bg-indigo-600 flex items-center justify-center flex-shrink-0 text-white text-xs font-bold">
                {{ strtoupper(substr(Auth::user()->name, 0, 1)) }}
            </div>
            <div class="flex-1 text-left min-w-0">
                <p class="text-sm font-medium text-gray-200 leading-tight truncate">{{ Auth::user()->name }}</p>
                <p class="text-[11px] text-gray-500 leading-tight truncate mt-0.5">{{ Auth::user()->email }}</p>
            </div>
            <svg class="w-3.5 h-3.5 text-gray-500 flex-shrink-0 transition-transform duration-200"
                 :class="{ '-rotate-180': profileOpen }"
                 fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="2" aria-hidden="true">
                <path stroke-linecap="round" stroke-linejoin="round" d="M19 9l-7 7-7-7"/>
            </svg>
        </button>

        {{-- Profile dropdown (slides up) --}}
        <div x-show="profileOpen" x-cloak
             @click.outside="profileOpen = false"
             x-transition:enter="transition ease-out duration-150"
             x-transition:enter-start="opacity-0 translate-y-2"
             x-transition:enter-end="opacity-100 translate-y-0"
             x-transition:leave="transition ease-in duration-100"
             x-transition:leave-start="opacity-100 translate-y-0"
             x-transition:leave-end="opacity-0 translate-y-2"
             class="border-t border-gray-800 py-1.5 px-3 space-y-0.5 bg-gray-900/95">

            <a href="{{ route('profile') }}" wire:navigate
               class="flex items-center gap-3 px-3 py-2 rounded-lg text-sm text-gray-400 hover:text-gray-100 hover:bg-white/5 transition-colors duration-100">
                <svg class="w-4 h-4 flex-shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="1.7" aria-hidden="true">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M16 7a4 4 0 11-8 0 4 4 0 018 0zM12 14a7 7 0 00-7 7h14a7 7 0 00-7-7z"/>
                </svg>
                Profile & Settings
            </a>

            <div class="pt-1 mt-1 border-t border-gray-800">
                <form method="POST" action="{{ route('logout') }}">
                    @csrf
                    <button type="submit"
                            class="w-full flex items-center gap-3 px-3 py-2 rounded-lg text-sm
                                   text-gray-400 hover:text-red-400 hover:bg-red-500/10 transition-colors duration-100">
                        <svg class="w-4 h-4 flex-shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="1.7" aria-hidden="true">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M17 16l4-4m0 0l-4-4m4 4H7m6 4v1a3 3 0 01-3 3H6a3 3 0 01-3-3V7a3 3 0 013-3h4a3 3 0 013 3v1"/>
                        </svg>
                        Log out
                    </button>
                </form>
            </div>

        </div>
    </div>

</aside>
