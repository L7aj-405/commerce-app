{{--
    Partial: store settings tab navigation.
    Variables: $store (Store), $activeTab ('general'|'whatsapp'|'whatsapp-templates'|'connections')
--}}
<div class="mb-8">
    {{-- Breadcrumb --}}
    <nav class="flex items-center gap-2 text-sm text-gray-500 dark:text-gray-400 mb-4" aria-label="Breadcrumb">
        <a href="{{ route('stores.index') }}" wire:navigate
            class="hover:text-gray-700 dark:hover:text-gray-200 transition-colors">
            Stores
        </a>
        <svg class="w-3.5 h-3.5 flex-shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="2" aria-hidden="true">
            <path stroke-linecap="round" stroke-linejoin="round" d="M9 5l7 7-7 7"/>
        </svg>
        <span class="text-gray-900 dark:text-white font-medium truncate">{{ $store->name }}</span>
    </nav>

    <h1 class="page-title">Store Settings</h1>

    {{-- Tab nav --}}
    <div class="mt-5 border-b border-gray-200 dark:border-gray-700">
        <nav class="-mb-px flex gap-6" aria-label="Store settings tabs">
            <a href="{{ route('stores.edit', $store) }}" wire:navigate
                class="whitespace-nowrap border-b-2 py-3 px-0.5 text-sm font-medium transition-colors
                    {{ $activeTab === 'general'
                        ? 'border-blue-500 text-blue-600 dark:text-blue-400'
                        : 'border-transparent text-gray-500 dark:text-gray-400 hover:text-gray-700 dark:hover:text-gray-200 hover:border-gray-300 dark:hover:border-gray-500' }}"
                @if($activeTab === 'general') aria-current="page" @endif>
                General
            </a>
            <a href="{{ route('stores.settings.whatsapp', $store) }}" wire:navigate
                class="whitespace-nowrap border-b-2 py-3 px-0.5 text-sm font-medium transition-colors
                    {{ $activeTab === 'whatsapp'
                        ? 'border-blue-500 text-blue-600 dark:text-blue-400'
                        : 'border-transparent text-gray-500 dark:text-gray-400 hover:text-gray-700 dark:hover:text-gray-200 hover:border-gray-300 dark:hover:border-gray-500' }}"
                @if($activeTab === 'whatsapp') aria-current="page" @endif>
                WhatsApp
            </a>
            <a href="{{ route('stores.settings.whatsapp.templates', $store) }}" wire:navigate
                class="whitespace-nowrap border-b-2 py-3 px-0.5 text-sm font-medium transition-colors
                    {{ $activeTab === 'whatsapp-templates'
                        ? 'border-blue-500 text-blue-600 dark:text-blue-400'
                        : 'border-transparent text-gray-500 dark:text-gray-400 hover:text-gray-700 dark:hover:text-gray-200 hover:border-gray-300 dark:hover:border-gray-500' }}"
                @if($activeTab === 'whatsapp-templates') aria-current="page" @endif>
                Templates
            </a>
            <a href="{{ route('dashboard.integrations.index') }}"
                class="whitespace-nowrap border-b-2 py-3 px-0.5 text-sm font-medium transition-colors
                    {{ $activeTab === 'connections'
                        ? 'border-blue-500 text-blue-600 dark:text-blue-400'
                        : 'border-transparent text-gray-500 dark:text-gray-400 hover:text-gray-700 dark:hover:text-gray-200 hover:border-gray-300 dark:hover:border-gray-500' }}"
                @if($activeTab === 'connections') aria-current="page" @endif>
                Integrations
            </a>
        </nav>
    </div>
</div>
