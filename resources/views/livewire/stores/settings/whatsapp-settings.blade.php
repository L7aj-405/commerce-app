<div class="space-y-6">

    @include('livewire.stores.settings-layout', ['store' => $store, 'activeTab' => 'whatsapp'])

    {{-- Flash --}}
    @if(session('success'))
        <div class="flex items-center gap-3 rounded-xl bg-green-50 dark:bg-green-500/10 border border-green-200 dark:border-green-500/20 px-4 py-3 text-sm text-green-800 dark:text-green-300">
            <svg class="w-4 h-4 flex-shrink-0 text-green-500" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="1.7" aria-hidden="true">
                <path stroke-linecap="round" stroke-linejoin="round" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z"/>
            </svg>
            {{ session('success') }}
        </div>
    @endif

    {{-- Connection status --}}
    @if($connectionStatus === 'connected')
        <div class="flex items-center gap-3 rounded-xl bg-green-50 dark:bg-green-500/10 border border-green-200 dark:border-green-500/20 px-4 py-3">
            <svg class="w-4 h-4 flex-shrink-0 text-green-500" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="1.7" aria-hidden="true">
                <path stroke-linecap="round" stroke-linejoin="round" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z"/>
            </svg>
            <span class="text-sm font-medium text-green-800 dark:text-green-300">Connected — token and phone number ID are valid.</span>
        </div>
    @elseif($connectionStatus === 'invalid')
        <div class="flex items-center gap-3 rounded-xl bg-red-50 dark:bg-red-500/10 border border-red-200 dark:border-red-500/20 px-4 py-3">
            <svg class="w-4 h-4 flex-shrink-0 text-red-500" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="1.7" aria-hidden="true">
                <path stroke-linecap="round" stroke-linejoin="round" d="M10 14l2-2m0 0l2-2m-2 2l-2-2m2 2l2 2m7-2a9 9 0 11-18 0 9 9 0 0118 0z"/>
            </svg>
            <span class="text-sm font-medium text-red-800 dark:text-red-300">Invalid — Meta API rejected the credentials. Check your token and phone number ID.</span>
        </div>
    @elseif($connectionStatus === 'not_configured')
        <div class="flex items-center gap-3 rounded-xl bg-yellow-50 dark:bg-yellow-500/10 border border-yellow-200 dark:border-yellow-500/20 px-4 py-3">
            <svg class="w-4 h-4 flex-shrink-0 text-yellow-500" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="1.7" aria-hidden="true">
                <path stroke-linecap="round" stroke-linejoin="round" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-3L13.732 4c-.77-1.333-2.694-1.333-3.464 0L3.34 16c-.77 1.333.192 3 1.732 3z"/>
            </svg>
            <span class="text-sm font-medium text-yellow-800 dark:text-yellow-300">Not configured — save your credentials first, then test the connection.</span>
        </div>
    @endif

    <form wire:submit="save">
        <div class="card space-y-6">

            {{-- Section header --}}
            <div class="border-b border-gray-100 dark:border-gray-700 pb-5">
                <h2 class="section-title flex items-center gap-2">
                    <svg class="w-5 h-5 text-green-500" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="1.7" aria-hidden="true">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M8 12h.01M12 12h.01M16 12h.01M21 12c0 4.418-4.03 8-9 8a9.863 9.863 0 01-4.255-.949L3 20l1.395-3.72C3.512 15.042 3 13.574 3 12c0-4.418 4.03-8 9-8s9 3.582 9 8z"/>
                    </svg>
                    WhatsApp Business API
                </h2>
                <p class="mt-1 text-sm text-gray-500 dark:text-gray-400">
                    Connect your Meta WhatsApp Business Account to enable automated order confirmations.
                    Find these values in the
                    <a href="https://developers.facebook.com/apps/" target="_blank" rel="noopener noreferrer"
                        class="text-blue-600 dark:text-blue-400 hover:underline">Meta for Developers dashboard</a>.
                </p>
            </div>

            {{-- Phone Number ID --}}
            <div>
                <label for="phoneNumberId" class="label">Phone Number ID <span class="text-red-500">*</span></label>
                <input type="text" id="phoneNumberId" wire:model="phoneNumberId"
                    placeholder="e.g. 123456789012345"
                    class="input mt-1.5 @error('phoneNumberId') border-red-300 dark:border-red-500 @enderror">
                @error('phoneNumberId') <p class="error-text mt-1">{{ $message }}</p> @enderror
                <p class="mt-1.5 text-xs text-gray-500 dark:text-gray-400">Found under WhatsApp → API Setup in your Meta app.</p>
            </div>

            {{-- Business Account ID --}}
            <div>
                <label for="businessAccountId" class="label">Business Account ID <span class="text-red-500">*</span></label>
                <input type="text" id="businessAccountId" wire:model="businessAccountId"
                    placeholder="e.g. 987654321098765"
                    class="input mt-1.5 @error('businessAccountId') border-red-300 dark:border-red-500 @enderror">
                @error('businessAccountId') <p class="error-text mt-1">{{ $message }}</p> @enderror
                <p class="mt-1.5 text-xs text-gray-500 dark:text-gray-400">Your WhatsApp Business Account (WABA) ID.</p>
            </div>

            {{-- Access Token --}}
            <div>
                <label for="accessToken" class="label">Permanent Access Token <span class="text-red-500">*</span></label>
                @if($hasExistingToken)
                    <div class="flex items-center gap-2 mt-1.5 mb-2 text-sm text-green-700 dark:text-green-400">
                        <svg class="w-4 h-4 flex-shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="1.7" aria-hidden="true">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z"/>
                        </svg>
                        <span>Token is saved. Enter a new value below to replace it.</span>
                    </div>
                @endif
                <input type="password" id="accessToken" wire:model="accessToken"
                    placeholder="{{ $hasExistingToken ? 'Leave blank to keep existing token' : 'Paste your permanent access token' }}"
                    class="input @error('accessToken') border-red-300 dark:border-red-500 @enderror {{ $hasExistingToken ? '' : 'mt-1.5' }}">
                @error('accessToken') <p class="error-text mt-1">{{ $message }}</p> @enderror
                <p class="mt-1.5 text-xs text-gray-500 dark:text-gray-400">Use a System User permanent token — do not use short-lived tokens.</p>
            </div>

            {{-- Webhook Verify Token --}}
            <div>
                <label for="webhookVerifyToken" class="label">Webhook Verify Token</label>
                <input type="text" id="webhookVerifyToken" wire:model="webhookVerifyToken"
                    placeholder="A secret string you choose (e.g. my_verify_secret)"
                    class="input mt-1.5 @error('webhookVerifyToken') border-red-300 dark:border-red-500 @enderror">
                @error('webhookVerifyToken') <p class="error-text mt-1">{{ $message }}</p> @enderror
                <p class="mt-1.5 text-xs text-gray-500 dark:text-gray-400">
                    Set this as the verify token when configuring your webhook in the Meta dashboard.
                    Endpoint: <code class="bg-gray-100 dark:bg-gray-700 text-gray-700 dark:text-gray-300 px-1.5 py-0.5 rounded text-xs font-mono">{{ url('/webhook/whatsapp') }}</code>
                </p>
            </div>

            {{-- Actions --}}
            <div class="flex items-center justify-between pt-2 border-t border-gray-100 dark:border-gray-700">
                <button type="button" wire:click="testConnection"
                    wire:loading.attr="disabled"
                    class="btn btn-secondary disabled:opacity-50">
                    <svg class="w-4 h-4" wire:loading.remove wire:target="testConnection" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="1.7" aria-hidden="true">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M13 10V3L4 14h7v7l9-11h-7z"/>
                    </svg>
                    <span wire:loading.remove wire:target="testConnection">Test Connection</span>
                    <span wire:loading wire:target="testConnection">Testing…</span>
                </button>

                <div class="flex items-center gap-3">
                    <a href="{{ route('stores.index') }}" wire:navigate class="btn btn-ghost">
                        Cancel
                    </a>
                    <button type="submit" wire:loading.attr="disabled" class="btn btn-primary disabled:opacity-50">
                        <span wire:loading.remove wire:target="save">Save Settings</span>
                        <span wire:loading wire:target="save">Saving…</span>
                    </button>
                </div>
            </div>

        </div>
    </form>

    {{-- ── Stats ──────────────────────────────────────────────────────────── --}}
    <div class="grid grid-cols-3 gap-4">

        <div class="card text-center py-5">
            <p class="text-2xl font-bold text-gray-900 dark:text-white tabular-nums">{{ $this->stats['sent'] }}</p>
            <p class="mt-1 text-xs text-gray-500 dark:text-gray-400 font-medium uppercase tracking-wide">Messages Sent</p>
        </div>

        <div class="card text-center py-5">
            <p class="text-2xl font-bold text-green-600 dark:text-green-400 tabular-nums">{{ $this->stats['confirmed'] }}</p>
            <p class="mt-1 text-xs text-gray-500 dark:text-gray-400 font-medium uppercase tracking-wide">Confirmed</p>
        </div>

        <div class="card text-center py-5">
            <p class="text-2xl font-bold text-red-500 dark:text-red-400 tabular-nums">{{ $this->stats['cancelled'] }}</p>
            <p class="mt-1 text-xs text-gray-500 dark:text-gray-400 font-medium uppercase tracking-wide">Cancelled</p>
        </div>

    </div>

    {{-- ── Message history ─────────────────────────────────────────────────── --}}
    <div class="card">

        <div class="border-b border-gray-100 dark:border-gray-700 pb-4 mb-5">
            <h2 class="section-title">Message History</h2>
            <p class="mt-0.5 text-sm text-gray-500 dark:text-gray-400">
                Orders where a WhatsApp confirmation was sent.
            </p>
        </div>

        @forelse ($this->messageHistory as $order)
            <div class="flex items-center justify-between gap-4 py-3
                        {{ ! $loop->last ? 'border-b border-gray-100 dark:border-gray-700' : '' }}">

                <div class="min-w-0">
                    <p class="text-sm font-medium text-gray-900 dark:text-white truncate">
                        {{ $order->customer_name ?? '—' }}
                        <span class="font-normal text-gray-500 dark:text-gray-400">#{{ $order->order_number }}</span>
                    </p>
                    <p class="text-xs text-gray-500 dark:text-gray-400 mt-0.5">
                        {{ $order->whatsapp_sent_at?->diffForHumans() }}
                    </p>
                </div>

                <div class="flex items-center gap-2 flex-shrink-0">
                    {{-- Order status --}}
                    <span @class([
                        'inline-flex items-center px-2 py-0.5 rounded-full text-xs font-medium',
                        'bg-yellow-100 text-yellow-800 dark:bg-yellow-900/40 dark:text-yellow-300' => $order->status === \App\Enums\OrderStatus::Pending,
                        'bg-blue-100 text-blue-800 dark:bg-blue-900/40 dark:text-blue-300'         => $order->status === \App\Enums\OrderStatus::Confirmed,
                        'bg-red-100 text-red-800 dark:bg-red-900/40 dark:text-red-300'             => $order->status === \App\Enums\OrderStatus::Cancelled,
                        'bg-green-100 text-green-800 dark:bg-green-900/40 dark:text-green-300'     => $order->status === \App\Enums\OrderStatus::Completed,
                        'bg-gray-100 text-gray-700 dark:bg-gray-700 dark:text-gray-300'            => $order->status === \App\Enums\OrderStatus::Failed,
                    ])>
                        {{ $order->status->label() }}
                    </span>

                    {{-- WhatsApp delivery state --}}
                    @if ($order->whatsapp_confirmed_at)
                        <span class="inline-flex items-center gap-1 px-2 py-0.5 rounded-full text-xs font-medium bg-green-100 text-green-700 dark:bg-green-900/40 dark:text-green-300">
                            <svg class="w-3 h-3" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="2" aria-hidden="true">
                                <path stroke-linecap="round" stroke-linejoin="round" d="M5 13l4 4L19 7"/>
                            </svg>
                            Confirmed
                        </span>
                    @elseif ($order->whatsapp_cancelled_at)
                        <span class="inline-flex items-center gap-1 px-2 py-0.5 rounded-full text-xs font-medium bg-red-100 text-red-700 dark:bg-red-900/40 dark:text-red-300">
                            <svg class="w-3 h-3" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="2" aria-hidden="true">
                                <path stroke-linecap="round" stroke-linejoin="round" d="M6 18L18 6M6 6l12 12"/>
                            </svg>
                            Cancelled
                        </span>
                    @else
                        <span class="inline-flex items-center px-2 py-0.5 rounded-full text-xs font-medium bg-gray-100 text-gray-600 dark:bg-gray-700 dark:text-gray-400">
                            Awaiting reply
                        </span>
                    @endif

                    <a href="{{ route('orders.show', $order) }}" wire:navigate
                        class="p-1.5 text-gray-400 hover:text-gray-600 dark:hover:text-gray-200 rounded-lg hover:bg-gray-100 dark:hover:bg-gray-700 transition-colors"
                        title="View order">
                        <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="1.7" aria-hidden="true">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M13.5 6H5.25A2.25 2.25 0 003 8.25v10.5A2.25 2.25 0 005.25 21h10.5A2.25 2.25 0 0018 18.75V10.5m-10.5 6L21 3m0 0h-5.25M21 3v5.25"/>
                        </svg>
                    </a>
                </div>

            </div>
        @empty
            <p class="py-6 text-center text-sm text-gray-400 dark:text-gray-500">
                No WhatsApp messages sent yet.
            </p>
        @endforelse

        @if ($this->messageHistory->hasPages())
            <div class="mt-5 pt-5 border-t border-gray-100 dark:border-gray-700">
                {{ $this->messageHistory->links() }}
            </div>
        @endif

    </div>

</div>
