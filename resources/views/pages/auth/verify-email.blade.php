<x-layouts::auth :title="__('Email verification')">
    <div class="mt-4 flex flex-col gap-6">

        <div class="flex flex-col gap-1 text-center">
            <flux:text>
                {{ __("We've sent a verification link to") }}
                <span class="font-semibold text-zinc-900 dark:text-white">{{ auth()->user()->email }}</span>
            </flux:text>
            <flux:text class="text-sm text-zinc-500 dark:text-zinc-400">
                {{ __("Check your spam folder if you don't see it.") }}
            </flux:text>
        </div>

        @if (session('status') == 'verification-link-sent')
            <flux:text class="text-center font-medium !text-green-600 dark:!text-green-400">
                {{ __('A new verification link has been sent to your email address.') }}
            </flux:text>
        @endif

        <div class="flex flex-col items-center gap-3"
             x-data="{
                 seconds: 0,
                 init() {
                     const exp = parseInt(localStorage.getItem('resend_exp') || '0');
                     this.seconds = Math.max(0, Math.ceil((exp - Date.now()) / 1000));
                     if (this.seconds > 0) this.startTick();
                 },
                 startTick() {
                     const t = setInterval(() => {
                         this.seconds--;
                         if (this.seconds <= 0) clearInterval(t);
                     }, 1000);
                 },
                 send() {
                     localStorage.setItem('resend_exp', Date.now() + 60000);
                     this.seconds = 60;
                     this.startTick();
                 }
             }">

            <form method="POST" action="{{ route('verification.send') }}" @submit="send()" class="w-full">
                @csrf
                <flux:button type="submit" variant="primary" class="w-full" x-bind:disabled="seconds > 0">
        {{-- أضفنا النص وإغلاق الوسم هنا --}}
        <span x-show="seconds <= 0">{{ __('Resend verification email') }}</span>
        <span x-show="seconds > 0" x-text="`{{ __('Resend in') }} ${seconds}s`" style="display:none"></span>
    </flux:button>
            </form>

            <form method="POST" action="{{ route('logout') }}">
                @csrf
                <flux:button variant="ghost" type="submit" class="text-sm cursor-pointer">
                    {{ __('Wrong email? Sign out and register again') }}
                </flux:button>
            </form>
        </div>

    </div>
</x-layouts::auth>
