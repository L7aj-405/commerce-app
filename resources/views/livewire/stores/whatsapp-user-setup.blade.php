<div class="min-h-screen bg-gradient-to-b from-gray-900 to-gray-850 py-10 px-4">
    <div class="max-w-2xl mx-auto">

        {{-- Back link --}}
        @if ($step !== 'complete')
            <a href="{{ route('stores.whatsapp.setup', $store) }}" wire:navigate
                class="inline-flex items-center gap-1.5 text-sm text-gray-500 hover:text-gray-300 mb-8 transition-colors">
                <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="1.7">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M15.75 19.5L8.25 12l7.5-7.5"/>
                </svg>
                Back to Setup
            </a>
        @endif

        {{-- ── Step: Token entry ──────────────────────────────── --}}
        @if ($step === 'token')
            <div class="bg-gray-800 border border-gray-700/60 rounded-2xl shadow-2xl overflow-hidden">
                <div class="p-8 md:p-10">

                    <div class="flex items-center gap-3 mb-6">
                        <div class="w-10 h-10 rounded-xl bg-gray-700 flex items-center justify-center flex-shrink-0">
                            <svg class="w-5 h-5 text-gray-300" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="1.7">
                                <path stroke-linecap="round" stroke-linejoin="round" d="M15.75 5.25a3 3 0 013 3m3 0a6 6 0 01-7.029 5.912c-.563-.097-1.159.026-1.563.43L10.5 17.25H8.25v2.25H6v2.25H2.25v-2.818c0-.597.237-1.17.659-1.591l6.499-6.499c.404-.404.527-1 .43-1.563A6 6 0 1121.75 8.25z"/>
                            </svg>
                        </div>
                        <div>
                            <h2 class="text-xl font-bold text-white">Enter Your Access Token</h2>
                            <p class="text-sm text-gray-400">From your Meta developer app</p>
                        </div>
                    </div>

                    {{-- Where to find the token --}}
                    <div class="p-4 bg-indigo-900/20 border border-indigo-700/40 rounded-xl mb-6 text-sm">
                        <p class="font-medium text-indigo-300 mb-2">Where to find your token:</p>
                        <ol class="space-y-1 text-indigo-400 list-decimal list-inside">
                            <li>Go to <span class="font-mono text-xs bg-indigo-900/40 px-1 rounded">developers.facebook.com/apps</span></li>
                            <li>Select your app → <strong class="text-indigo-300">WhatsApp → API Setup</strong></li>
                            <li>Under "Send and receive messages", copy the <strong class="text-indigo-300">System User access token</strong> (permanent token)</li>
                        </ol>
                    </div>

                    @error('accessToken')
                        <div class="flex items-start gap-2 mb-4 text-sm text-red-400 bg-red-500/10 border border-red-500/20 rounded-lg px-4 py-3">
                            <svg class="w-4 h-4 flex-shrink-0 mt-0.5" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="1.7">
                                <path stroke-linecap="round" stroke-linejoin="round" d="M12 9v3.75m9-.75a9 9 0 11-18 0 9 9 0 0118 0zm-9 3.75h.008v.008H12v-.008z"/>
                            </svg>
                            {{ $message }}
                        </div>
                    @enderror

                    <div class="mb-6">
                        <label class="block text-sm font-medium text-gray-300 mb-1.5">
                            Permanent Access Token <span class="text-red-400">*</span>
                        </label>
                        <textarea wire:model="accessToken" rows="3"
                            placeholder="EAABwzLixnjYBO..."
                            class="w-full bg-gray-900 border border-gray-600 rounded-lg px-4 py-3 text-white text-sm placeholder-gray-600 font-mono focus:outline-none focus:ring-2 focus:ring-indigo-500 focus:border-transparent resize-none @error('accessToken') border-red-500/60 @enderror"></textarea>
                        <p class="mt-1.5 text-xs text-gray-500">Use a System User permanent token — short-lived page tokens expire and will stop working.</p>
                    </div>

                    <button wire:click="validateToken"
                        wire:loading.attr="disabled"
                        class="w-full flex items-center justify-center gap-2 px-6 py-3 bg-indigo-600 hover:bg-indigo-500 disabled:opacity-60 text-white text-sm font-medium rounded-xl transition-all duration-150">
                        <span wire:loading.remove wire:target="validateToken" class="flex items-center gap-2">
                            <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="1.7">
                                <path stroke-linecap="round" stroke-linejoin="round" d="M9 12.75L11.25 15 15 9.75m-3-7.036A11.959 11.959 0 013.598 6 11.99 11.99 0 003 9.749c0 5.592 3.824 10.29 9 11.623 5.176-1.332 9-6.03 9-11.622 0-1.31-.21-2.571-.598-3.751h-.152c-3.196 0-6.1-1.248-8.25-3.285z"/>
                            </svg>
                            Validate &amp; Continue
                        </span>
                        <span wire:loading wire:target="validateToken" class="flex items-center gap-2">
                            <svg class="w-4 h-4 animate-spin" fill="none" viewBox="0 0 24 24">
                                <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"/>
                                <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4z"/>
                            </svg>
                            Validating token…
                        </span>
                    </button>

                </div>
            </div>
        @endif

        {{-- ── Step: Account selection ─────────────────────────── --}}
        @if ($step === 'account')
            <div class="bg-gray-800 border border-gray-700/60 rounded-2xl shadow-2xl overflow-hidden">
                <div class="p-8 md:p-10">

                    <div class="mb-6">
                        <div class="flex items-center gap-2 mb-1">
                            <div class="w-2 h-2 rounded-full bg-green-400"></div>
                            <span class="text-xs text-green-400 font-medium">Token verified</span>
                        </div>
                        <h2 class="text-xl font-bold text-white">Select Business Account</h2>
                        <p class="mt-1 text-sm text-gray-400">{{ count($accounts) }} accounts found for this token.</p>
                    </div>

                    @error('selectedBusinessId')
                        <div class="flex items-center gap-2 mb-4 text-sm text-red-400 bg-red-500/10 border border-red-500/20 rounded-lg px-4 py-2.5">
                            <svg class="w-4 h-4 flex-shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="1.7">
                                <path stroke-linecap="round" stroke-linejoin="round" d="M12 9v3.75m9-.75a9 9 0 11-18 0 9 9 0 0118 0zm-9 3.75h.008v.008H12v-.008z"/>
                            </svg>
                            {{ $message }}
                        </div>
                    @enderror

                    <div class="space-y-3">
                        @foreach ($accounts as $account)
                            <button wire:click="selectAccount('{{ $account['id'] }}')"
                                wire:loading.attr="disabled"
                                wire:loading.class="opacity-50"
                                wire:target="selectAccount('{{ $account['id'] }}')"
                                class="w-full flex items-center gap-4 p-4 rounded-xl border border-gray-600/60 hover:border-indigo-500/60 hover:bg-indigo-600/5 text-left transition-all duration-150 group disabled:cursor-wait">
                                <div class="w-9 h-9 rounded-lg bg-gray-700 flex items-center justify-center flex-shrink-0">
                                    <svg class="w-4 h-4 text-gray-400" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="1.7">
                                        <path stroke-linecap="round" stroke-linejoin="round" d="M8 12h.01M12 12h.01M16 12h.01M21 12c0 4.418-4.03 8-9 8a9.863 9.863 0 01-4.255-.949L3 20l1.395-3.72C3.512 15.042 3 13.574 3 12c0-4.418 4.03-8 9-8s9 3.582 9 8z"/>
                                    </svg>
                                </div>
                                <div class="flex-1 min-w-0">
                                    <p class="text-sm font-medium text-white truncate">{{ $account['name'] ?? 'Business Account' }}</p>
                                    <p class="text-xs text-gray-500 font-mono">{{ $account['id'] }}</p>
                                </div>
                                <svg wire:loading.remove wire:target="selectAccount('{{ $account['id'] }}')"
                                    class="w-4 h-4 text-gray-600 group-hover:text-indigo-400 transition-colors flex-shrink-0"
                                    fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="2">
                                    <path stroke-linecap="round" stroke-linejoin="round" d="M13.5 4.5L21 12m0 0l-7.5 7.5M21 12H3"/>
                                </svg>
                                <svg wire:loading wire:target="selectAccount('{{ $account['id'] }}')"
                                    class="w-4 h-4 text-indigo-400 animate-spin flex-shrink-0"
                                    fill="none" viewBox="0 0 24 24">
                                    <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"/>
                                    <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4z"/>
                                </svg>
                            </button>
                        @endforeach
                    </div>

                    <div class="mt-6 pt-5 border-t border-gray-700/60">
                        <button wire:click="$set('step', 'token')"
                            class="text-sm text-gray-500 hover:text-gray-300 transition-colors flex items-center gap-1.5">
                            <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="1.7">
                                <path stroke-linecap="round" stroke-linejoin="round" d="M15.75 19.5L8.25 12l7.5-7.5"/>
                            </svg>
                            Use a different token
                        </button>
                    </div>

                </div>
            </div>
        @endif

        {{-- ── Step: Phone selection ───────────────────────────── --}}
        @if ($step === 'phone')
            <div class="bg-gray-800 border border-gray-700/60 rounded-2xl shadow-2xl overflow-hidden">
                <div class="p-8 md:p-10">

                    <div class="mb-6">
                        <div class="flex items-center gap-2 mb-1">
                            <div class="w-2 h-2 rounded-full bg-green-400"></div>
                            <span class="text-xs text-green-400 font-medium">Account selected</span>
                        </div>
                        <h2 class="text-xl font-bold text-white">Select Phone Number</h2>
                        <p class="mt-1 text-sm text-gray-400">{{ count($phones) }} numbers found. Choose the one to use for order confirmations.</p>
                    </div>

                    @error('selectedPhoneId')
                        <div class="flex items-center gap-2 mb-4 text-sm text-red-400 bg-red-500/10 border border-red-500/20 rounded-lg px-4 py-2.5">
                            <svg class="w-4 h-4 flex-shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="1.7">
                                <path stroke-linecap="round" stroke-linejoin="round" d="M12 9v3.75m9-.75a9 9 0 11-18 0 9 9 0 0118 0zm-9 3.75h.008v.008H12v-.008z"/>
                            </svg>
                            {{ $message }}
                        </div>
                    @enderror

                    <div class="space-y-3">
                        @foreach ($phones as $phone)
                            <button wire:click="selectPhone('{{ $phone['id'] }}')"
                                wire:loading.attr="disabled"
                                wire:target="selectPhone('{{ $phone['id'] }}')"
                                class="w-full flex items-center gap-4 p-4 rounded-xl border border-gray-600/60 hover:border-indigo-500/60 hover:bg-indigo-600/5 text-left transition-all duration-150 group disabled:cursor-wait">
                                <div class="w-9 h-9 rounded-lg bg-gray-700 flex items-center justify-center flex-shrink-0">
                                    <svg class="w-4 h-4 text-gray-400" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="1.7">
                                        <path stroke-linecap="round" stroke-linejoin="round" d="M10.5 1.5H8.25A2.25 2.25 0 006 3.75v16.5a2.25 2.25 0 002.25 2.25h7.5A2.25 2.25 0 0018 20.25V3.75a2.25 2.25 0 00-2.25-2.25H13.5m-3 0V3h3V1.5m-3 0h3m-3 8.25h3m-3 4.5h3"/>
                                    </svg>
                                </div>
                                <div class="flex-1 min-w-0">
                                    <p class="text-sm font-medium text-white">{{ $phone['display_phone_number'] ?? $phone['id'] }}</p>
                                    <div class="flex items-center gap-2 mt-0.5">
                                        <span class="text-xs text-gray-500 font-mono">{{ $phone['id'] }}</span>
                                        @if (! empty($phone['verified_name']))
                                            <span class="text-xs text-green-400">{{ $phone['verified_name'] }}</span>
                                        @endif
                                        @if (! empty($phone['quality_rating']))
                                            <span @class([
                                                'text-xs px-1.5 py-0.5 rounded-full',
                                                'bg-green-500/15 text-green-400' => $phone['quality_rating'] === 'GREEN',
                                                'bg-yellow-500/15 text-yellow-400' => $phone['quality_rating'] === 'YELLOW',
                                                'bg-red-500/15 text-red-400' => $phone['quality_rating'] === 'RED',
                                            ])>{{ $phone['quality_rating'] }}</span>
                                        @endif
                                    </div>
                                </div>
                                <svg wire:loading.remove wire:target="selectPhone('{{ $phone['id'] }}')"
                                    class="w-4 h-4 text-gray-600 group-hover:text-indigo-400 transition-colors flex-shrink-0"
                                    fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="2">
                                    <path stroke-linecap="round" stroke-linejoin="round" d="M13.5 4.5L21 12m0 0l-7.5 7.5M21 12H3"/>
                                </svg>
                                <svg wire:loading wire:target="selectPhone('{{ $phone['id'] }}')"
                                    class="w-4 h-4 text-indigo-400 animate-spin flex-shrink-0"
                                    fill="none" viewBox="0 0 24 24">
                                    <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"/>
                                    <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4z"/>
                                </svg>
                            </button>
                        @endforeach
                    </div>

                    <div class="mt-6 pt-5 border-t border-gray-700/60">
                        <button wire:click="$set('step', 'account')"
                            class="text-sm text-gray-500 hover:text-gray-300 transition-colors flex items-center gap-1.5">
                            <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="1.7">
                                <path stroke-linecap="round" stroke-linejoin="round" d="M15.75 19.5L8.25 12l7.5-7.5"/>
                            </svg>
                            Back to accounts
                        </button>
                    </div>

                </div>
            </div>
        @endif

        {{-- ── Step: Complete ──────────────────────────────────── --}}
        @if ($step === 'complete')
            <div class="bg-gray-800 border border-gray-700/60 rounded-2xl shadow-2xl overflow-hidden">
                <div class="p-8 md:p-10 text-center">

                    <div class="w-16 h-16 rounded-full bg-green-500/15 border border-green-500/30 flex items-center justify-center mx-auto mb-5">
                        <svg class="w-8 h-8 text-green-400" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="1.7">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M9 12.75L11.25 15 15 9.75M21 12a9 9 0 11-18 0 9 9 0 0118 0z"/>
                        </svg>
                    </div>

                    <h2 class="text-xl font-bold text-white mb-2">WhatsApp Connected</h2>
                    <p class="text-sm text-gray-400 max-w-sm mx-auto">
                        Your credentials have been verified and saved. Order confirmations are ready to send.
                    </p>

                    <div class="mt-4 inline-flex items-center gap-2 text-sm text-gray-500 bg-gray-900/60 border border-gray-700/60 rounded-lg px-4 py-2.5">
                        <svg class="w-4 h-4 text-green-500" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="1.7">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M16.5 10.5V6.75a4.5 4.5 0 10-9 0v3.75m-.75 11.25h10.5a2.25 2.25 0 002.25-2.25v-6.75a2.25 2.25 0 00-2.25-2.25H6.75a2.25 2.25 0 00-2.25 2.25v6.75a2.25 2.25 0 002.25 2.25z"/>
                        </svg>
                        Token and phone number saved encrypted
                    </div>

                    <div class="mt-7 flex items-center justify-center gap-3">
                        <a href="{{ route('stores.settings.whatsapp', $store) }}" wire:navigate
                            class="flex items-center gap-2 px-6 py-2.5 bg-indigo-600 hover:bg-indigo-500 text-white text-sm font-medium rounded-lg transition-colors">
                            <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="1.7">
                                <path stroke-linecap="round" stroke-linejoin="round" d="M9.594 3.94c.09-.542.56-.94 1.11-.94h2.593c.55 0 1.02.398 1.11.94l.213 1.281c.063.374.313.686.645.87.074.04.147.083.22.127.325.196.72.257 1.075.124l1.217-.456a1.125 1.125 0 011.37.49l1.296 2.247a1.125 1.125 0 01-.26 1.431l-1.003.827c-.293.241-.438.613-.43.992a6.759 6.759 0 010 .255c-.008.378.137.75.43.991l1.004.827c.424.35.534.954.26 1.43l-1.298 2.247a1.125 1.125 0 01-1.369.491l-1.217-.456c-.355-.133-.75-.072-1.076.124a6.57 6.57 0 01-.22.128c-.331.183-.581.495-.644.869l-.213 1.281c-.09.543-.56.94-1.11.94h-2.594c-.55 0-1.019-.398-1.11-.94l-.213-1.281c-.062-.374-.312-.686-.644-.87a6.52 6.52 0 01-.22-.127c-.325-.196-.72-.257-1.076-.124l-1.217.456a1.125 1.125 0 01-1.369-.49l-1.297-2.247a1.125 1.125 0 01.26-1.431l1.004-.827c.292-.24.437-.613.43-.991a6.932 6.932 0 010-.255c.007-.38-.138-.751-.43-.992l-1.004-.827a1.125 1.125 0 01-.26-1.43l1.297-2.247a1.125 1.125 0 011.37-.491l1.216.456c.356.133.751.072 1.076-.124.072-.044.146-.086.22-.128.332-.183.582-.495.644-.869l.214-1.28z"/><path stroke-linecap="round" stroke-linejoin="round" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z"/>
                            </svg>
                            WhatsApp Settings
                        </a>
                        <a href="{{ route('dashboard') }}" wire:navigate
                            class="px-5 py-2.5 text-gray-400 hover:text-white text-sm transition-colors">
                            Dashboard
                        </a>
                    </div>

                </div>
            </div>
        @endif

    </div>
</div>
