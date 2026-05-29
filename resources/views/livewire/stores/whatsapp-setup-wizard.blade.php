<div class="min-h-screen bg-gradient-to-b from-gray-900 to-gray-850 py-10 px-4">
    <div class="max-w-3xl mx-auto">

        {{-- Back link --}}
        <a href="{{ route('stores.settings.whatsapp', $store) }}" wire:navigate
            class="inline-flex items-center gap-1.5 text-sm text-gray-500 hover:text-gray-300 mb-8 transition-colors">
            <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="1.7">
                <path stroke-linecap="round" stroke-linejoin="round" d="M15.75 19.5L8.25 12l7.5-7.5"/>
            </svg>
            Back to WhatsApp Settings
        </a>

        {{-- ── Step indicator ───────────────────────────────────── --}}
        @if ($currentStep < 6)
            <div class="flex items-center mb-10">
                @foreach ($stepLabels as $i => $label)
                    @php $stepNum = $i + 1; @endphp

                    {{-- Circle --}}
                    <div class="flex flex-col items-center relative z-10">
                        <div @class([
                            'w-9 h-9 rounded-full flex items-center justify-center text-sm font-semibold transition-all duration-200',
                            'bg-indigo-600 text-white shadow-lg shadow-indigo-900/40' => $currentStep === $stepNum,
                            'bg-indigo-600/20 text-indigo-400 ring-1 ring-indigo-600/40' => $currentStep > $stepNum,
                            'bg-gray-800 text-gray-500 ring-1 ring-gray-700' => $currentStep < $stepNum,
                        ])>
                            @if ($currentStep > $stepNum)
                                <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="2.5">
                                    <path stroke-linecap="round" stroke-linejoin="round" d="M4.5 12.75l6 6 9-13.5"/>
                                </svg>
                            @else
                                {{ $stepNum }}
                            @endif
                        </div>
                        <span @class([
                            'mt-1.5 text-[10px] font-medium hidden sm:block tracking-wide',
                            'text-indigo-400' => $currentStep === $stepNum,
                            'text-gray-600' => $currentStep !== $stepNum,
                        ])>{{ strtoupper($label) }}</span>
                    </div>

                    {{-- Connector line --}}
                    @if ($i < count($stepLabels) - 1)
                        <div @class([
                            'flex-1 h-px mx-2 transition-all duration-300',
                            'bg-indigo-600/40' => $currentStep > $stepNum,
                            'bg-gray-700' => $currentStep <= $stepNum,
                        ])></div>
                    @endif
                @endforeach
            </div>
        @endif

        {{-- ── Main card ───────────────────────────────────────── --}}
        <div class="bg-gray-800 border border-gray-700/60 rounded-2xl shadow-2xl overflow-hidden">

            {{-- ── STEP 1: Choose method ──────────────────────── --}}
            @if ($currentStep === 1)
                <div class="p-8 md:p-10">
                    <h2 class="text-2xl font-bold text-white mb-1">Connect WhatsApp</h2>
                    <p class="text-gray-400 mb-8">Choose how you'd like to connect WhatsApp to your store.</p>

                    {{-- Error --}}
                    @error('method')
                        <div class="flex items-center gap-2 mb-5 text-sm text-red-400 bg-red-500/10 border border-red-500/20 rounded-lg px-4 py-2.5">
                            <svg class="w-4 h-4 flex-shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="1.7">
                                <path stroke-linecap="round" stroke-linejoin="round" d="M12 9v3.75m9-.75a9 9 0 11-18 0 9 9 0 0118 0zm-9 3.75h.008v.008H12v-.008z"/>
                            </svg>
                            {{ $message }}
                        </div>
                    @enderror

                    <div class="grid gap-4">

                        {{-- Method 1: SaaS App --}}
                        <button wire:click="selectMethod('saas_app')"
                            @class([
                                'w-full p-6 rounded-xl border-2 text-left transition-all duration-150',
                                'border-indigo-500 bg-indigo-600/10 shadow-lg shadow-indigo-900/20' => $method === 'saas_app',
                                'border-gray-600/60 hover:border-gray-500 bg-gray-700/30 hover:bg-gray-700/60' => $method !== 'saas_app',
                            ])>
                            <div class="flex items-start gap-4">
                                <div class="w-11 h-11 rounded-xl bg-indigo-600 flex items-center justify-center flex-shrink-0">
                                    <svg class="w-5 h-5 text-white" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="1.7">
                                        <path stroke-linecap="round" stroke-linejoin="round" d="M3.75 13.5l10.5-11.25L12 10.5h8.25L9.75 21.75 12 13.5H3.75z"/>
                                    </svg>
                                </div>
                                <div class="flex-1">
                                    <div class="flex items-center gap-2 mb-1">
                                        <h3 class="font-bold text-white text-base">SaaS App</h3>
                                        <span class="text-[10px] font-semibold bg-green-500/15 text-green-400 border border-green-500/30 px-2 py-0.5 rounded-full">Recommended</span>
                                    </div>
                                    <p class="text-gray-400 text-sm mb-4">Connect via Facebook login — 2-minute setup, no developer account needed.</p>
                                    <ul class="space-y-2 text-sm">
                                        <li class="flex items-center gap-2 text-green-400">
                                            <svg class="w-3.5 h-3.5 flex-shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="2.5"><path stroke-linecap="round" stroke-linejoin="round" d="M4.5 12.75l6 6 9-13.5"/></svg>
                                            Official Meta integration
                                        </li>
                                        <li class="flex items-center gap-2 text-green-400">
                                            <svg class="w-3.5 h-3.5 flex-shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="2.5"><path stroke-linecap="round" stroke-linejoin="round" d="M4.5 12.75l6 6 9-13.5"/></svg>
                                            Automatic token refresh
                                        </li>
                                        <li class="flex items-center gap-2 text-green-400">
                                            <svg class="w-3.5 h-3.5 flex-shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="2.5"><path stroke-linecap="round" stroke-linejoin="round" d="M4.5 12.75l6 6 9-13.5"/></svg>
                                            Perfect for small and medium businesses
                                        </li>
                                    </ul>
                                </div>
                                @if ($method === 'saas_app')
                                    <svg class="w-5 h-5 text-indigo-400 flex-shrink-0 mt-0.5" fill="currentColor" viewBox="0 0 20 20">
                                        <path fill-rule="evenodd" d="M10 18a8 8 0 100-16 8 8 0 000 16zm3.857-9.809a.75.75 0 00-1.214-.882l-3.483 4.79-1.88-1.88a.75.75 0 10-1.06 1.061l2.5 2.5a.75.75 0 001.137-.089l4-5.5z" clip-rule="evenodd"/>
                                    </svg>
                                @endif
                            </div>
                        </button>

                        {{-- Method 2: User's Own App --}}
                        <button wire:click="selectMethod('user_app')"
                            @class([
                                'w-full p-6 rounded-xl border-2 text-left transition-all duration-150',
                                'border-indigo-500 bg-indigo-600/10 shadow-lg shadow-indigo-900/20' => $method === 'user_app',
                                'border-gray-600/60 hover:border-gray-500 bg-gray-700/30 hover:bg-gray-700/60' => $method !== 'user_app',
                            ])>
                            <div class="flex items-start gap-4">
                                <div class="w-11 h-11 rounded-xl bg-gray-600 flex items-center justify-center flex-shrink-0">
                                    <svg class="w-5 h-5 text-gray-200" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="1.7">
                                        <path stroke-linecap="round" stroke-linejoin="round" d="M15.75 5.25a3 3 0 013 3m3 0a6 6 0 01-7.029 5.912c-.563-.097-1.159.026-1.563.43L10.5 17.25H8.25v2.25H6v2.25H2.25v-2.818c0-.597.237-1.17.659-1.591l6.499-6.499c.404-.404.527-1 .43-1.563A6 6 0 1121.75 8.25z"/>
                                    </svg>
                                </div>
                                <div class="flex-1">
                                    <div class="flex items-center gap-2 mb-1">
                                        <h3 class="font-bold text-white text-base">Your Own App</h3>
                                        <span class="text-[10px] font-semibold bg-gray-700 text-gray-400 border border-gray-600 px-2 py-0.5 rounded-full">Advanced</span>
                                    </div>
                                    <p class="text-gray-400 text-sm mb-4">Use your own Meta developer app with API keys — full control over credentials.</p>
                                    <ul class="space-y-2 text-sm">
                                        <li class="flex items-center gap-2 text-green-400">
                                            <svg class="w-3.5 h-3.5 flex-shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="2.5"><path stroke-linecap="round" stroke-linejoin="round" d="M4.5 12.75l6 6 9-13.5"/></svg>
                                            Full ownership of credentials
                                        </li>
                                        <li class="flex items-center gap-2 text-green-400">
                                            <svg class="w-3.5 h-3.5 flex-shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="2.5"><path stroke-linecap="round" stroke-linejoin="round" d="M4.5 12.75l6 6 9-13.5"/></svg>
                                            No platform dependency
                                        </li>
                                        <li class="flex items-center gap-2 text-yellow-400">
                                            <svg class="w-3.5 h-3.5 flex-shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="1.7"><path stroke-linecap="round" stroke-linejoin="round" d="M12 9v3.75m9-.75a9 9 0 11-18 0 9 9 0 0118 0zm-9 3.75h.008v.008H12v-.008z"/></svg>
                                            Requires Meta developer account (~10 min)
                                        </li>
                                    </ul>
                                </div>
                                @if ($method === 'user_app')
                                    <svg class="w-5 h-5 text-indigo-400 flex-shrink-0 mt-0.5" fill="currentColor" viewBox="0 0 20 20">
                                        <path fill-rule="evenodd" d="M10 18a8 8 0 100-16 8 8 0 000 16zm3.857-9.809a.75.75 0 00-1.214-.882l-3.483 4.79-1.88-1.88a.75.75 0 10-1.06 1.061l2.5 2.5a.75.75 0 001.137-.089l4-5.5z" clip-rule="evenodd"/>
                                    </svg>
                                @endif
                            </div>
                        </button>

                    </div>

                    {{-- Nav --}}
                    <div class="flex items-center justify-between mt-8 pt-6 border-t border-gray-700/60">
                        <a href="{{ route('stores.settings.whatsapp', $store) }}" wire:navigate
                            class="text-sm text-gray-500 hover:text-gray-300 transition-colors">Cancel</a>
                        <button wire:click="nextStep"
                            wire:loading.attr="disabled"
                            class="flex items-center gap-2 px-6 py-2.5 bg-indigo-600 hover:bg-indigo-500 disabled:opacity-50 text-white text-sm font-medium rounded-lg transition-all duration-150">
                            <span wire:loading.remove wire:target="nextStep">Continue</span>
                            <span wire:loading wire:target="nextStep">Redirecting…</span>
                            <svg wire:loading.remove wire:target="nextStep" class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="2">
                                <path stroke-linecap="round" stroke-linejoin="round" d="M13.5 4.5L21 12m0 0l-7.5 7.5M21 12H3"/>
                            </svg>
                        </button>
                    </div>
                </div>
            @endif

            {{-- ── STEP 2: Create Meta Developer App ─────────── --}}
            @if ($currentStep === 2)
                <div class="p-8 md:p-10">
                    <h2 class="text-2xl font-bold text-white mb-1">Create a Meta App</h2>
                    <p class="text-gray-400 mb-8">Follow these steps to create your Meta developer app and enable WhatsApp.</p>

                    <ol class="space-y-5">
                        <li class="flex gap-4">
                            <span class="w-7 h-7 rounded-full bg-indigo-600/20 text-indigo-400 text-sm font-bold flex items-center justify-center flex-shrink-0 mt-0.5 ring-1 ring-indigo-600/40">1</span>
                            <div>
                                <p class="text-white text-sm font-medium">Go to Meta for Developers</p>
                                <p class="text-gray-400 text-sm mt-0.5">Visit <span class="font-mono text-indigo-400 text-xs bg-gray-900 px-1.5 py-0.5 rounded">developers.facebook.com/apps</span> and log in with your Facebook account.</p>
                            </div>
                        </li>
                        <li class="flex gap-4">
                            <span class="w-7 h-7 rounded-full bg-indigo-600/20 text-indigo-400 text-sm font-bold flex items-center justify-center flex-shrink-0 mt-0.5 ring-1 ring-indigo-600/40">2</span>
                            <div>
                                <p class="text-white text-sm font-medium">Create a new app</p>
                                <p class="text-gray-400 text-sm mt-0.5">Click <strong class="text-gray-200">Create App</strong>, choose <strong class="text-gray-200">Business</strong> type, then fill in the app name and contact email.</p>
                            </div>
                        </li>
                        <li class="flex gap-4">
                            <span class="w-7 h-7 rounded-full bg-indigo-600/20 text-indigo-400 text-sm font-bold flex items-center justify-center flex-shrink-0 mt-0.5 ring-1 ring-indigo-600/40">3</span>
                            <div>
                                <p class="text-white text-sm font-medium">Add the WhatsApp product</p>
                                <p class="text-gray-400 text-sm mt-0.5">In the app dashboard, find <strong class="text-gray-200">Add a product</strong> and click <strong class="text-gray-200">Set up</strong> next to WhatsApp.</p>
                            </div>
                        </li>
                        <li class="flex gap-4">
                            <span class="w-7 h-7 rounded-full bg-indigo-600/20 text-indigo-400 text-sm font-bold flex items-center justify-center flex-shrink-0 mt-0.5 ring-1 ring-indigo-600/40">4</span>
                            <div>
                                <p class="text-white text-sm font-medium">Connect a WhatsApp Business Account</p>
                                <p class="text-gray-400 text-sm mt-0.5">Follow the prompts to link or create a WhatsApp Business Account (WABA). This is where your messages will originate.</p>
                            </div>
                        </li>
                    </ol>

                    <div class="mt-6 p-4 bg-yellow-500/10 border border-yellow-500/20 rounded-xl text-sm text-yellow-300">
                        <div class="flex gap-2">
                            <svg class="w-4 h-4 flex-shrink-0 mt-0.5" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="1.7">
                                <path stroke-linecap="round" stroke-linejoin="round" d="M12 9v3.75m9-.75a9 9 0 11-18 0 9 9 0 0118 0zm-9 3.75h.008v.008H12v-.008z"/>
                            </svg>
                            <span>Leave this wizard open while you set up the Meta app — you'll need values from it in the next steps.</span>
                        </div>
                    </div>

                    <div class="flex items-center justify-between mt-8 pt-6 border-t border-gray-700/60">
                        <button wire:click="prevStep" class="flex items-center gap-1.5 text-sm text-gray-500 hover:text-gray-300 transition-colors">
                            <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="1.7"><path stroke-linecap="round" stroke-linejoin="round" d="M15.75 19.5L8.25 12l7.5-7.5"/></svg>
                            Back
                        </button>
                        <button wire:click="nextStep" class="flex items-center gap-2 px-6 py-2.5 bg-indigo-600 hover:bg-indigo-500 text-white text-sm font-medium rounded-lg transition-colors">
                            App created — continue
                            <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M13.5 4.5L21 12m0 0l-7.5 7.5M21 12H3"/></svg>
                        </button>
                    </div>
                </div>
            @endif

            {{-- ── STEP 3: Phone Number ID + Business Account ID ── --}}
            @if ($currentStep === 3)
                <div class="p-8 md:p-10">
                    <h2 class="text-2xl font-bold text-white mb-1">Account Identifiers</h2>
                    <p class="text-gray-400 mb-8">Find these in your Meta app under <span class="text-gray-200 font-medium">WhatsApp → API Setup</span>.</p>

                    <div class="space-y-5">
                        <div>
                            <label class="block text-sm font-medium text-gray-300 mb-1.5">
                                Phone Number ID <span class="text-red-400">*</span>
                            </label>
                            <input type="text" wire:model="phoneNumberId"
                                placeholder="e.g. 123456789012345"
                                class="w-full bg-gray-900 border border-gray-600 rounded-lg px-4 py-2.5 text-white text-sm placeholder-gray-500 focus:outline-none focus:ring-2 focus:ring-indigo-500 focus:border-transparent @error('phoneNumberId') border-red-500 @enderror">
                            @error('phoneNumberId')
                                <p class="mt-1 text-xs text-red-400">{{ $message }}</p>
                            @enderror
                            <p class="mt-1.5 text-xs text-gray-500">Found under WhatsApp → API Setup in your Meta app.</p>
                        </div>

                        <div>
                            <label class="block text-sm font-medium text-gray-300 mb-1.5">
                                WhatsApp Business Account ID (WABA) <span class="text-red-400">*</span>
                            </label>
                            <input type="text" wire:model="businessAccountId"
                                placeholder="e.g. 987654321098765"
                                class="w-full bg-gray-900 border border-gray-600 rounded-lg px-4 py-2.5 text-white text-sm placeholder-gray-500 focus:outline-none focus:ring-2 focus:ring-indigo-500 focus:border-transparent @error('businessAccountId') border-red-500 @enderror">
                            @error('businessAccountId')
                                <p class="mt-1 text-xs text-red-400">{{ $message }}</p>
                            @enderror
                            <p class="mt-1.5 text-xs text-gray-500">Your WhatsApp Business Account ID — different from the Phone Number ID.</p>
                        </div>
                    </div>

                    <div class="flex items-center justify-between mt-8 pt-6 border-t border-gray-700/60">
                        <button wire:click="prevStep" class="flex items-center gap-1.5 text-sm text-gray-500 hover:text-gray-300 transition-colors">
                            <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="1.7"><path stroke-linecap="round" stroke-linejoin="round" d="M15.75 19.5L8.25 12l7.5-7.5"/></svg>
                            Back
                        </button>
                        <button wire:click="nextStep" class="flex items-center gap-2 px-6 py-2.5 bg-indigo-600 hover:bg-indigo-500 text-white text-sm font-medium rounded-lg transition-colors">
                            Continue
                            <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M13.5 4.5L21 12m0 0l-7.5 7.5M21 12H3"/></svg>
                        </button>
                    </div>
                </div>
            @endif

            {{-- ── STEP 4: Access Token ────────────────────────── --}}
            @if ($currentStep === 4)
                <div class="p-8 md:p-10">
                    <h2 class="text-2xl font-bold text-white mb-1">Permanent Access Token</h2>
                    <p class="text-gray-400 mb-8">Create a System User token so your integration doesn't expire.</p>

                    <ol class="space-y-4 mb-8">
                        <li class="flex gap-4">
                            <span class="w-7 h-7 rounded-full bg-indigo-600/20 text-indigo-400 text-sm font-bold flex items-center justify-center flex-shrink-0 mt-0.5 ring-1 ring-indigo-600/40">1</span>
                            <div class="text-sm">
                                <p class="text-white font-medium">Go to Business Settings</p>
                                <p class="text-gray-400 mt-0.5">Open <span class="text-gray-300 font-mono text-xs bg-gray-900 px-1.5 py-0.5 rounded">business.facebook.com → Settings → System Users</span></p>
                            </div>
                        </li>
                        <li class="flex gap-4">
                            <span class="w-7 h-7 rounded-full bg-indigo-600/20 text-indigo-400 text-sm font-bold flex items-center justify-center flex-shrink-0 mt-0.5 ring-1 ring-indigo-600/40">2</span>
                            <div class="text-sm">
                                <p class="text-white font-medium">Create a System User</p>
                                <p class="text-gray-400 mt-0.5">Add a new System User, assign it <strong class="text-gray-200">Admin</strong> role on your WhatsApp Business Account.</p>
                            </div>
                        </li>
                        <li class="flex gap-4">
                            <span class="w-7 h-7 rounded-full bg-indigo-600/20 text-indigo-400 text-sm font-bold flex items-center justify-center flex-shrink-0 mt-0.5 ring-1 ring-indigo-600/40">3</span>
                            <div class="text-sm">
                                <p class="text-white font-medium">Generate a token</p>
                                <p class="text-gray-400 mt-0.5">Click <strong class="text-gray-200">Generate New Token</strong>, select your app, enable <strong class="text-gray-200">whatsapp_business_messaging</strong> and <strong class="text-gray-200">whatsapp_business_management</strong> scopes. Set expiry to <strong class="text-gray-200">Never</strong>.</p>
                            </div>
                        </li>
                    </ol>

                    <div>
                        <label class="block text-sm font-medium text-gray-300 mb-1.5">
                            Access Token <span class="text-red-400">*</span>
                        </label>
                        <input type="password" wire:model="accessToken"
                            placeholder="Paste your permanent access token"
                            class="w-full bg-gray-900 border border-gray-600 rounded-lg px-4 py-2.5 text-white text-sm placeholder-gray-500 focus:outline-none focus:ring-2 focus:ring-indigo-500 focus:border-transparent @error('accessToken') border-red-500 @enderror">
                        @error('accessToken')
                            <p class="mt-1 text-xs text-red-400">{{ $message }}</p>
                        @enderror
                        <p class="mt-1.5 text-xs text-gray-500">This token is stored encrypted and never shown again after saving.</p>
                    </div>

                    <div class="flex items-center justify-between mt-8 pt-6 border-t border-gray-700/60">
                        <button wire:click="prevStep" class="flex items-center gap-1.5 text-sm text-gray-500 hover:text-gray-300 transition-colors">
                            <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="1.7"><path stroke-linecap="round" stroke-linejoin="round" d="M15.75 19.5L8.25 12l7.5-7.5"/></svg>
                            Back
                        </button>
                        <button wire:click="nextStep" class="flex items-center gap-2 px-6 py-2.5 bg-indigo-600 hover:bg-indigo-500 text-white text-sm font-medium rounded-lg transition-colors">
                            Continue
                            <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M13.5 4.5L21 12m0 0l-7.5 7.5M21 12H3"/></svg>
                        </button>
                    </div>
                </div>
            @endif

            {{-- ── STEP 5: Webhook ─────────────────────────────── --}}
            @if ($currentStep === 5)
                <div class="p-8 md:p-10">
                    <h2 class="text-2xl font-bold text-white mb-1">Set Up Webhook</h2>
                    <p class="text-gray-400 mb-8">Webhooks let Meta notify your store when customers reply to WhatsApp messages.</p>

                    {{-- Webhook URL display --}}
                    <div class="mb-6 p-4 bg-gray-900 rounded-xl border border-gray-700">
                        <p class="text-xs font-semibold text-gray-500 uppercase tracking-wider mb-2">Your Webhook URL</p>
                        <div class="flex items-center gap-3">
                            <code class="flex-1 text-indigo-300 text-sm font-mono break-all">{{ url('/webhook/whatsapp') }}</code>
                            <button type="button"
                                x-data
                                @click="navigator.clipboard.writeText('{{ url('/webhook/whatsapp') }}').then(() => { $el.textContent = 'Copied!'; setTimeout(() => $el.textContent = 'Copy', 2000) })"
                                class="text-xs text-gray-400 hover:text-white bg-gray-700 hover:bg-gray-600 px-3 py-1.5 rounded-lg transition-colors flex-shrink-0">
                                Copy
                            </button>
                        </div>
                    </div>

                    <ol class="space-y-4 mb-8">
                        <li class="flex gap-4">
                            <span class="w-7 h-7 rounded-full bg-indigo-600/20 text-indigo-400 text-sm font-bold flex items-center justify-center flex-shrink-0 mt-0.5 ring-1 ring-indigo-600/40">1</span>
                            <div class="text-sm">
                                <p class="text-white font-medium">Open your Meta app → WhatsApp → Configuration</p>
                                <p class="text-gray-400 mt-0.5">Click <strong class="text-gray-200">Edit</strong> next to Webhook.</p>
                            </div>
                        </li>
                        <li class="flex gap-4">
                            <span class="w-7 h-7 rounded-full bg-indigo-600/20 text-indigo-400 text-sm font-bold flex items-center justify-center flex-shrink-0 mt-0.5 ring-1 ring-indigo-600/40">2</span>
                            <div class="text-sm">
                                <p class="text-white font-medium">Paste the Webhook URL above and your Verify Token below</p>
                                <p class="text-gray-400 mt-0.5">Meta will call your URL with the verify token to confirm ownership.</p>
                            </div>
                        </li>
                        <li class="flex gap-4">
                            <span class="w-7 h-7 rounded-full bg-indigo-600/20 text-indigo-400 text-sm font-bold flex items-center justify-center flex-shrink-0 mt-0.5 ring-1 ring-indigo-600/40">3</span>
                            <div class="text-sm">
                                <p class="text-white font-medium">Subscribe to <code class="bg-gray-900 px-1 rounded text-xs">messages</code></p>
                                <p class="text-gray-400 mt-0.5">After verifying, enable the <strong class="text-gray-200">messages</strong> webhook field.</p>
                            </div>
                        </li>
                    </ol>

                    <div>
                        <label class="block text-sm font-medium text-gray-300 mb-1.5">Verify Token</label>
                        <input type="text" wire:model="webhookVerifyToken"
                            placeholder="A secret string you choose (e.g. my_verify_secret)"
                            class="w-full bg-gray-900 border border-gray-600 rounded-lg px-4 py-2.5 text-white text-sm placeholder-gray-500 focus:outline-none focus:ring-2 focus:ring-indigo-500 focus:border-transparent">
                        <p class="mt-1.5 text-xs text-gray-500">Optional but recommended. Use the same value in the Meta webhook configuration.</p>
                    </div>

                    <div class="flex items-center justify-between mt-8 pt-6 border-t border-gray-700/60">
                        <button wire:click="prevStep" class="flex items-center gap-1.5 text-sm text-gray-500 hover:text-gray-300 transition-colors">
                            <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="1.7"><path stroke-linecap="round" stroke-linejoin="round" d="M15.75 19.5L8.25 12l7.5-7.5"/></svg>
                            Back
                        </button>
                        <button wire:click="nextStep"
                            wire:loading.attr="disabled"
                            class="flex items-center gap-2 px-6 py-2.5 bg-indigo-600 hover:bg-indigo-500 disabled:opacity-50 text-white text-sm font-medium rounded-lg transition-colors">
                            <span wire:loading.remove wire:target="nextStep">Save &amp; Finish</span>
                            <span wire:loading wire:target="nextStep">Saving…</span>
                            <svg wire:loading.remove wire:target="nextStep" class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M13.5 4.5L21 12m0 0l-7.5 7.5M21 12H3"/></svg>
                        </button>
                    </div>
                </div>
            @endif

            {{-- ── STEP 6: Complete ────────────────────────────── --}}
            @if ($currentStep === 6)
                <div class="p-8 md:p-10 text-center">
                    <div class="w-16 h-16 rounded-full bg-green-500/15 border border-green-500/30 flex items-center justify-center mx-auto mb-5">
                        <svg class="w-8 h-8 text-green-400" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="1.7">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M9 12.75L11.25 15 15 9.75M21 12a9 9 0 11-18 0 9 9 0 0118 0z"/>
                        </svg>
                    </div>

                    <h2 class="text-2xl font-bold text-white mb-2">WhatsApp Connected</h2>
                    <p class="text-gray-400 max-w-md mx-auto">
                        Your WhatsApp Business credentials have been saved. Order confirmations are ready to send.
                    </p>

                    <div class="mt-4 p-4 bg-gray-900/60 border border-gray-700/60 rounded-xl max-w-sm mx-auto">
                        <div class="space-y-2 text-sm">
                            <div class="flex items-center gap-2 text-gray-400">
                                <svg class="w-4 h-4 text-green-500 flex-shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="2.5"><path stroke-linecap="round" stroke-linejoin="round" d="M4.5 12.75l6 6 9-13.5"/></svg>
                                Credentials saved and encrypted
                            </div>
                            <div class="flex items-center gap-2 text-gray-400">
                                <svg class="w-4 h-4 text-green-500 flex-shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="2.5"><path stroke-linecap="round" stroke-linejoin="round" d="M4.5 12.75l6 6 9-13.5"/></svg>
                                Order automation enabled
                            </div>
                            <div class="flex items-center gap-2 text-gray-400">
                                <svg class="w-4 h-4 text-green-500 flex-shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="2.5"><path stroke-linecap="round" stroke-linejoin="round" d="M4.5 12.75l6 6 9-13.5"/></svg>
                                Webhook ready to receive replies
                            </div>
                        </div>
                    </div>

                    <div class="mt-8 flex items-center justify-center gap-3">
                        <a href="{{ route('stores.settings.whatsapp', $store) }}" wire:navigate
                            class="flex items-center gap-2 px-6 py-2.5 bg-indigo-600 hover:bg-indigo-500 text-white text-sm font-medium rounded-lg transition-colors">
                            <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="1.7"><path stroke-linecap="round" stroke-linejoin="round" d="M9.594 3.94c.09-.542.56-.94 1.11-.94h2.593c.55 0 1.02.398 1.11.94l.213 1.281c.063.374.313.686.645.87.074.04.147.083.22.127.325.196.72.257 1.075.124l1.217-.456a1.125 1.125 0 011.37.49l1.296 2.247a1.125 1.125 0 01-.26 1.431l-1.003.827c-.293.241-.438.613-.43.992a6.759 6.759 0 010 .255c-.008.378.137.75.43.991l1.004.827c.424.35.534.954.26 1.43l-1.298 2.247a1.125 1.125 0 01-1.369.491l-1.217-.456c-.355-.133-.75-.072-1.076.124a6.57 6.57 0 01-.22.128c-.331.183-.581.495-.644.869l-.213 1.281c-.09.543-.56.94-1.11.94h-2.594c-.55 0-1.019-.398-1.11-.94l-.213-1.281c-.062-.374-.312-.686-.644-.87a6.52 6.52 0 01-.22-.127c-.325-.196-.72-.257-1.076-.124l-1.217.456a1.125 1.125 0 01-1.369-.49l-1.297-2.247a1.125 1.125 0 01.26-1.431l1.004-.827c.292-.24.437-.613.43-.991a6.932 6.932 0 010-.255c.007-.38-.138-.751-.43-.992l-1.004-.827a1.125 1.125 0 01-.26-1.43l1.297-2.247a1.125 1.125 0 011.37-.491l1.216.456c.356.133.751.072 1.076-.124.072-.044.146-.086.22-.128.332-.183.582-.495.644-.869l.214-1.28z"/><path stroke-linecap="round" stroke-linejoin="round" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z"/></svg>
                            WhatsApp Settings
                        </a>
                        <a href="{{ route('dashboard') }}" wire:navigate
                            class="px-6 py-2.5 text-gray-400 hover:text-white text-sm font-medium transition-colors">
                            Dashboard
                        </a>
                    </div>
                </div>
            @endif

        </div>
    </div>
</div>
