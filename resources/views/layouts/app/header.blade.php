<header class="h-16 bg-white dark:bg-gray-900 border-b border-gray-200 dark:border-gray-800
              flex items-center justify-between px-6 flex-shrink-0 sticky top-0 z-30">

    {{-- Left: current route breadcrumb --}}
    <div class="flex items-center gap-2 text-sm">
        <span class="font-semibold text-gray-900 dark:text-white">
            @if(request()->routeIs('dashboard'))         Dashboard
            @elseif(request()->routeIs('orders.*'))      Orders
            @elseif(request()->routeIs('stores.products.*') || request()->routeIs('warehouses.*')) Products
            @elseif(request()->routeIs('stores.*'))      Stores
            @elseif(request()->routeIs('profile') || request()->routeIs('settings.*')) Settings
            @else {{ config('app.name') }}
            @endif
        </span>
    </div>

    {{-- Right: actions --}}
    <div class="flex items-center gap-2">

        {{-- Search --}}
        <div class="hidden lg:flex items-center relative">
            <svg class="w-4 h-4 absolute left-3 text-gray-400 pointer-events-none" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="1.7" aria-hidden="true">
                <path stroke-linecap="round" stroke-linejoin="round" d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z"/>
            </svg>
            <input type="search"
                   placeholder="Search…"
                   aria-label="Search"
                   class="w-56 pl-9 pr-4 py-1.5 text-sm bg-gray-100 dark:bg-gray-800
                          text-gray-900 dark:text-white placeholder:text-gray-400
                          border border-transparent rounded-lg
                          focus:outline-none focus:ring-2 focus:ring-blue-500 focus:bg-white dark:focus:bg-gray-700
                          transition duration-150">
        </div>

        {{-- Dark mode toggle --}}
        <button @click="darkMode = !darkMode"
                class="btn-icon"
                :aria-label="darkMode ? 'Switch to light mode' : 'Switch to dark mode'"
                :title="darkMode ? 'Light mode' : 'Dark mode'">
            {{-- Sun (show in dark mode to switch to light) --}}
            <svg x-show="darkMode" x-cloak class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="1.7" aria-hidden="true">
                <path stroke-linecap="round" stroke-linejoin="round" d="M12 3v1m0 16v1m9-9h-1M4 12H3m15.364 6.364l-.707-.707M6.343 6.343l-.707-.707m12.728 0l-.707.707M6.343 17.657l-.707.707M16 12a4 4 0 11-8 0 4 4 0 018 0z"/>
            </svg>
            {{-- Moon (show in light mode to switch to dark) --}}
            <svg x-show="!darkMode" class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="1.7" aria-hidden="true">
                <path stroke-linecap="round" stroke-linejoin="round" d="M20.354 15.354A9 9 0 018.646 3.646 9.003 9.003 0 0012 21a9.003 9.003 0 008.354-5.646z"/>
            </svg>
        </button>

        {{-- Notifications bell --}}
        <button class="btn-icon relative" aria-label="Notifications">
            <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="1.7" aria-hidden="true">
                <path stroke-linecap="round" stroke-linejoin="round" d="M15 17h5l-1.405-1.405A2.032 2.032 0 0118 14.158V11a6.002 6.002 0 00-4-5.659V5a2 2 0 10-4 0v.341C7.67 6.165 6 8.388 6 11v3.159c0 .538-.214 1.055-.595 1.436L4 17h5m6 0v1a3 3 0 11-6 0v-1m6 0H9"/>
            </svg>
        </button>

        {{-- User menu --}}
        <div x-data="{ open: false }" class="relative">
            <button @click="open = !open"
                    @keydown.escape.window="open = false"
                    class="flex items-center gap-2.5 pl-2 pr-3 py-1.5 rounded-lg
                           hover:bg-gray-100 dark:hover:bg-gray-800
                           transition-all duration-150 focus:outline-none focus-visible:ring-2 focus-visible:ring-blue-500"
                    :aria-expanded="open"
                    aria-haspopup="true">
                <div class="w-7 h-7 rounded-full bg-blue-600 flex items-center justify-center flex-shrink-0">
                    <span class="text-xs font-semibold text-white leading-none">
                        {{ strtoupper(substr(Auth::user()->name, 0, 1)) }}
                    </span>
                </div>
                <div class="hidden sm:block text-left">
                    <p class="text-sm font-medium text-gray-900 dark:text-white leading-tight">{{ Auth::user()->name }}</p>
                </div>
                <svg class="w-4 h-4 text-gray-400 transition-transform duration-150"
                     :class="{ 'rotate-180': open }"
                     fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="2" aria-hidden="true">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M19 9l-7 7-7-7"/>
                </svg>
            </button>

            {{-- Dropdown --}}
            <div x-show="open" x-cloak
                 @click.outside="open = false"
                 x-transition:enter="transition ease-out duration-150"
                 x-transition:enter-start="opacity-0 scale-95 -translate-y-1"
                 x-transition:enter-end="opacity-100 scale-100 translate-y-0"
                 x-transition:leave="transition ease-in duration-100"
                 x-transition:leave-start="opacity-100 scale-100 translate-y-0"
                 x-transition:leave-end="opacity-0 scale-95 -translate-y-1"
                 class="absolute right-0 top-full mt-2 w-52 bg-white dark:bg-gray-800
                        border border-gray-200 dark:border-gray-700 rounded-xl shadow-lg
                        py-1 z-50"
                 role="menu">

                <div class="px-4 py-2.5 border-b border-gray-100 dark:border-gray-700">
                    <p class="text-sm font-medium text-gray-900 dark:text-white truncate">{{ Auth::user()->name }}</p>
                    <p class="text-xs text-gray-500 dark:text-gray-400 truncate">{{ Auth::user()->email }}</p>
                </div>

                <a href="{{ route('profile') }}"
                   class="flex items-center gap-3 px-4 py-2.5 text-sm text-gray-700 dark:text-gray-300
                          hover:bg-gray-50 dark:hover:bg-gray-700 transition-colors duration-100"
                   role="menuitem">
                    <svg class="w-4 h-4 text-gray-400" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="1.7" aria-hidden="true">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M16 7a4 4 0 11-8 0 4 4 0 018 0zM12 14a7 7 0 00-7 7h14a7 7 0 00-7-7z"/>
                    </svg>
                    Profile & Settings
                </a>

                <div class="border-t border-gray-100 dark:border-gray-700 mt-1 pt-1">
                    <form method="POST" action="{{ route('logout') }}">
                        @csrf
                        <button type="submit"
                                class="w-full flex items-center gap-3 px-4 py-2.5 text-sm text-red-600 dark:text-red-400
                                       hover:bg-red-50 dark:hover:bg-red-500/10 transition-colors duration-100"
                                role="menuitem">
                            <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="1.7" aria-hidden="true">
                                <path stroke-linecap="round" stroke-linejoin="round" d="M17 16l4-4m0 0l-4-4m4 4H7m6 4v1a3 3 0 01-3 3H6a3 3 0 01-3-3V7a3 3 0 013-3h4a3 3 0 013 3v1"/>
                            </svg>
                            Log out
                        </button>
                    </form>
                </div>
            </div>
        </div>

    </div>
</header>
