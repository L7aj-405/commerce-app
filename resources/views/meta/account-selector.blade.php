<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Select Business Account — {{ config('app.name') }}</title>
    <script>if (localStorage.getItem('darkMode') === 'true') { document.documentElement.classList.add('dark'); }</script>
    <link rel="preconnect" href="https://fonts.bunny.net">
    <link href="https://fonts.bunny.net/css?family=inter:400,500,600,700&display=swap" rel="stylesheet"/>
    @vite(['resources/css/app.css', 'resources/js/app.js'])
</head>
<body class="bg-gray-900 text-white" style="font-family: 'Inter', sans-serif;">

<div class="min-h-screen flex items-center justify-center py-12 px-4">
    <div class="w-full max-w-xl">

        {{-- Logo / breadcrumb --}}
        <div class="flex items-center gap-3 mb-8">
            <div class="w-8 h-8 bg-indigo-600 rounded-lg flex items-center justify-center">
                <svg class="w-4 h-4 text-white" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="1.7">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M20 7l-8-4-8 4m16 0l-8 4m8-4v10l-8 4m0-10L4 7m8 4v10M4 7v10l8 4"/>
                </svg>
            </div>
            <span class="text-sm text-gray-400">WhatsApp Setup &rsaquo; Select Account</span>
        </div>

        {{-- Card --}}
        <div class="bg-gray-800 border border-gray-700/60 rounded-2xl shadow-2xl overflow-hidden">
            <div class="p-8">

                <div class="mb-6">
                    <h1 class="text-xl font-bold text-white">Select Business Account</h1>
                    <p class="mt-1 text-sm text-gray-400">Choose which WhatsApp Business Account to connect.</p>
                </div>

                @if (session('error'))
                    <div class="flex items-center gap-2 mb-5 text-sm text-red-400 bg-red-500/10 border border-red-500/20 rounded-lg px-4 py-2.5">
                        <svg class="w-4 h-4 flex-shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="1.7">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M12 9v3.75m9-.75a9 9 0 11-18 0 9 9 0 0118 0zm-9 3.75h.008v.008H12v-.008z"/>
                        </svg>
                        {{ session('error') }}
                    </div>
                @endif

                <form method="POST" action="{{ route('auth.meta.select-account.post') }}">
                    @csrf

                    <div class="space-y-3 mb-6">
                        @foreach ($businesses as $business)
                            <label class="flex items-center gap-4 p-4 rounded-xl border border-gray-600/60 hover:border-indigo-500/60 hover:bg-indigo-600/5 cursor-pointer transition-all duration-150">
                                <input type="radio" name="business_id" value="{{ $business['id'] }}"
                                    class="w-4 h-4 text-indigo-600 bg-gray-700 border-gray-500 focus:ring-indigo-500 focus:ring-offset-gray-800"
                                    {{ $loop->first ? 'checked' : '' }}>
                                <div class="flex-1 min-w-0">
                                    <p class="text-sm font-medium text-white truncate">{{ $business['name'] ?? 'Business Account' }}</p>
                                    <p class="text-xs text-gray-500 font-mono mt-0.5">ID: {{ $business['id'] }}</p>
                                </div>
                                <svg class="w-4 h-4 text-gray-600 flex-shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="1.7">
                                    <path stroke-linecap="round" stroke-linejoin="round" d="M8 12h.01M12 12h.01M16 12h.01M21 12c0 4.418-4.03 8-9 8a9.863 9.863 0 01-4.255-.949L3 20l1.395-3.72C3.512 15.042 3 13.574 3 12c0-4.418 4.03-8 9-8s9 3.582 9 8z"/>
                                </svg>
                            </label>
                        @endforeach

                        @if ($errors->has('business_id'))
                            <p class="text-xs text-red-400">{{ $errors->first('business_id') }}</p>
                        @endif
                    </div>

                    <div class="flex items-center justify-between pt-5 border-t border-gray-700/60">
                        <a href="{{ route('stores.index') }}"
                            class="text-sm text-gray-500 hover:text-gray-300 transition-colors">
                            Cancel
                        </a>
                        <button type="submit"
                            class="flex items-center gap-2 px-6 py-2.5 bg-indigo-600 hover:bg-indigo-500 text-white text-sm font-medium rounded-lg transition-colors">
                            Continue
                            <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="2">
                                <path stroke-linecap="round" stroke-linejoin="round" d="M13.5 4.5L21 12m0 0l-7.5 7.5M21 12H3"/>
                            </svg>
                        </button>
                    </div>
                </form>

            </div>
        </div>

        <p class="text-center text-xs text-gray-600 mt-6">Authorized via Facebook OAuth &middot; {{ config('app.name') }}</p>
    </div>
</div>

</body>
</html>
