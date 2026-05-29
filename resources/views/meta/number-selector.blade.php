<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Select Phone Number — {{ config('app.name') }}</title>
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
            <span class="text-sm text-gray-400">WhatsApp Setup &rsaquo; Select Phone Number</span>
        </div>

        {{-- Card --}}
        <div class="bg-gray-800 border border-gray-700/60 rounded-2xl shadow-2xl overflow-hidden">
            <div class="p-8">

                <div class="mb-6">
                    <h1 class="text-xl font-bold text-white">Select Phone Number</h1>
                    <p class="mt-1 text-sm text-gray-400">Choose the WhatsApp number to use for order confirmations.</p>
                </div>

                @if (session('error'))
                    <div class="flex items-center gap-2 mb-5 text-sm text-red-400 bg-red-500/10 border border-red-500/20 rounded-lg px-4 py-2.5">
                        <svg class="w-4 h-4 flex-shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="1.7">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M12 9v3.75m9-.75a9 9 0 11-18 0 9 9 0 0118 0zm-9 3.75h.008v.008H12v-.008z"/>
                        </svg>
                        {{ session('error') }}
                    </div>
                @endif

                <form method="POST" action="{{ route('auth.meta.select-number.post') }}">
                    @csrf

                    <div class="space-y-3 mb-6">
                        @foreach ($phones as $phone)
                            <label class="flex items-center gap-4 p-4 rounded-xl border border-gray-600/60 hover:border-indigo-500/60 hover:bg-indigo-600/5 cursor-pointer transition-all duration-150">
                                <input type="radio" name="phone_id" value="{{ $phone['id'] }}"
                                    class="w-4 h-4 text-indigo-600 bg-gray-700 border-gray-500 focus:ring-indigo-500 focus:ring-offset-gray-800"
                                    {{ $loop->first ? 'checked' : '' }}>
                                <div class="flex-1 min-w-0">
                                    <p class="text-sm font-medium text-white">{{ $phone['display_phone_number'] ?? $phone['id'] }}</p>
                                    <div class="flex items-center gap-3 mt-0.5">
                                        <p class="text-xs text-gray-500 font-mono">ID: {{ $phone['id'] }}</p>
                                        @if (! empty($phone['verified_name']))
                                            <span class="text-xs text-green-400">{{ $phone['verified_name'] }}</span>
                                        @endif
                                        @if (! empty($phone['quality_rating']))
                                            <span @class([
                                                'text-xs px-1.5 py-0.5 rounded-full font-medium',
                                                'bg-green-500/15 text-green-400' => $phone['quality_rating'] === 'GREEN',
                                                'bg-yellow-500/15 text-yellow-400' => $phone['quality_rating'] === 'YELLOW',
                                                'bg-red-500/15 text-red-400' => $phone['quality_rating'] === 'RED',
                                                'bg-gray-700 text-gray-400' => ! in_array($phone['quality_rating'], ['GREEN', 'YELLOW', 'RED']),
                                            ])>{{ $phone['quality_rating'] }}</span>
                                        @endif
                                    </div>
                                </div>
                                <svg class="w-4 h-4 text-gray-600 flex-shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="1.7">
                                    <path stroke-linecap="round" stroke-linejoin="round" d="M10.5 1.5H8.25A2.25 2.25 0 006 3.75v16.5a2.25 2.25 0 002.25 2.25h7.5A2.25 2.25 0 0018 20.25V3.75a2.25 2.25 0 00-2.25-2.25H13.5m-3 0V3h3V1.5m-3 0h3m-3 8.25h3m-3 4.5h3"/>
                                </svg>
                            </label>
                        @endforeach

                        @if ($errors->has('phone_id'))
                            <p class="text-xs text-red-400">{{ $errors->first('phone_id') }}</p>
                        @endif
                    </div>

                    <div class="flex items-center justify-between pt-5 border-t border-gray-700/60">
                        <a href="{{ route('auth.meta.select-account') }}"
                            class="flex items-center gap-1.5 text-sm text-gray-500 hover:text-gray-300 transition-colors">
                            <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="1.7">
                                <path stroke-linecap="round" stroke-linejoin="round" d="M15.75 19.5L8.25 12l7.5-7.5"/>
                            </svg>
                            Back
                        </a>
                        <button type="submit"
                            class="flex items-center gap-2 px-6 py-2.5 bg-indigo-600 hover:bg-indigo-500 text-white text-sm font-medium rounded-lg transition-colors">
                            Connect this number
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
