<div class="space-y-6">

    @include('livewire.stores.settings-layout', ['store' => $store, 'activeTab' => 'whatsapp'])

    {{-- Flash --}}
    <div
        x-data="{ show: false, message: '' }"
        x-on:notify.window="show = true; message = $event.detail.message; setTimeout(() => show = false, 3500)"
        x-show="show"
        x-cloak
        x-transition:enter="transition ease-out duration-200"
        x-transition:enter-start="opacity-0 translate-y-1"
        x-transition:enter-end="opacity-100 translate-y-0"
        x-transition:leave="transition ease-in duration-150"
        x-transition:leave-start="opacity-100"
        x-transition:leave-end="opacity-0"
        class="flex items-center gap-3 rounded-xl bg-green-50 dark:bg-green-500/10 border border-green-200 dark:border-green-500/20 px-4 py-3 text-sm text-green-800 dark:text-green-300">
        <svg class="w-4 h-4 flex-shrink-0 text-green-500" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="1.7">
            <path stroke-linecap="round" stroke-linejoin="round" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z"/>
        </svg>
        <span x-text="message"></span>
    </div>

    <div class="grid grid-cols-1 xl:grid-cols-5 gap-6">

        {{-- ── Template picker (left / top) ────────────────── --}}
        <div class="xl:col-span-2 space-y-3">

            <div class="border-b border-gray-100 dark:border-gray-700 pb-3 mb-1">
                <h2 class="section-title">Message Template</h2>
                <p class="mt-0.5 text-sm text-gray-500 dark:text-gray-400">
                    Choose how order confirmation messages are formatted.
                </p>
            </div>

            @foreach ($templates as $key => $template)
                <button wire:click="select('{{ $key }}')"
                    @class([
                        'w-full text-left rounded-xl border-2 p-4 transition-all duration-150',
                        'border-indigo-500 bg-indigo-50 dark:bg-indigo-600/10 shadow-sm' => $selected === $key,
                        'border-gray-200 dark:border-gray-700 hover:border-indigo-300 dark:hover:border-indigo-600/50 hover:bg-gray-50 dark:hover:bg-white/5' => $selected !== $key,
                    ])>
                    <div class="flex items-start justify-between gap-3">
                        <div class="flex-1 min-w-0">
                            <div class="flex items-center gap-2">
                                <span @class([
                                    'text-sm font-semibold',
                                    'text-indigo-700 dark:text-indigo-400' => $selected === $key,
                                    'text-gray-900 dark:text-gray-100' => $selected !== $key,
                                ])>{{ $template['name'] }}</span>

                                @if ($selected === $key)
                                    <svg class="w-4 h-4 text-indigo-600 dark:text-indigo-400 flex-shrink-0" fill="currentColor" viewBox="0 0 20 20">
                                        <path fill-rule="evenodd" d="M10 18a8 8 0 100-16 8 8 0 000 16zm3.857-9.809a.75.75 0 00-1.214-.882l-3.483 4.79-1.88-1.88a.75.75 0 10-1.06 1.061l2.5 2.5a.75.75 0 001.137-.089l4-5.5z" clip-rule="evenodd"/>
                                    </svg>
                                @endif
                            </div>
                            <p class="mt-0.5 text-xs text-gray-500 dark:text-gray-400 leading-relaxed">{{ $template['description'] }}</p>
                        </div>
                    </div>

                    {{-- Button previews --}}
                    @if (! empty($template['buttons']))
                        <div class="mt-3 flex flex-wrap gap-1.5">
                            @foreach ($template['buttons'] as $btn)
                                <span class="text-[10px] font-medium bg-gray-100 dark:bg-gray-700 text-gray-600 dark:text-gray-400 px-2 py-0.5 rounded-full">
                                    {{ $btn['title'] }}
                                </span>
                            @endforeach
                        </div>
                    @endif
                </button>
            @endforeach

        </div>

        {{-- ── Live preview (right / bottom) ───────────────── --}}
        <div class="xl:col-span-3">

            <div class="card h-full flex flex-col">

                <div class="border-b border-gray-100 dark:border-gray-700 pb-4 mb-5">
                    <h3 class="section-title">Preview</h3>
                    <p class="mt-0.5 text-sm text-gray-500 dark:text-gray-400">
                        How the message will appear to your customers.
                    </p>
                </div>

                {{-- WhatsApp bubble mock --}}
                <div class="flex-1 bg-gray-100 dark:bg-gray-900/60 rounded-xl p-4 relative overflow-hidden">

                    {{-- Subtle grid pattern --}}
                    <div class="absolute inset-0 opacity-[0.03] dark:opacity-[0.06]"
                         style="background-image: url(\"data:image/svg+xml,%3Csvg width='20' height='20' viewBox='0 0 20 20' xmlns='http://www.w3.org/2000/svg'%3E%3Cg fill='%239C92AC' fill-opacity='1' fill-rule='evenodd'%3E%3Ccircle cx='3' cy='3' r='3'/%3E%3Ccircle cx='13' cy='13' r='3'/%3E%3C/g%3E%3C/svg%3E\")"></div>

                    <div class="relative max-w-xs">

                        {{-- Bubble --}}
                        <div class="bg-white dark:bg-gray-800 rounded-2xl rounded-tl-sm shadow-md px-4 py-3">
                            <pre class="text-xs text-gray-800 dark:text-gray-200 whitespace-pre-wrap font-sans leading-relaxed">{{ $this->preview }}</pre>

                            {{-- Timestamp mock --}}
                            <p class="text-right text-[10px] text-gray-400 dark:text-gray-500 mt-1.5">12:34</p>
                        </div>

                        {{-- Action buttons mock --}}
                        @if (! empty($this->buttons))
                            <div class="mt-2 space-y-1.5">
                                @foreach ($this->buttons as $btn)
                                    <div class="bg-white dark:bg-gray-800 rounded-xl shadow-sm px-4 py-2 text-center">
                                        <span class="text-xs font-medium text-blue-600 dark:text-blue-400">{{ $btn['title'] }}</span>
                                    </div>
                                @endforeach
                            </div>
                        @endif

                    </div>
                </div>

                {{-- Save --}}
                <div class="mt-5 pt-5 border-t border-gray-100 dark:border-gray-700 flex items-center justify-between">
                    <p class="text-xs text-gray-400 dark:text-gray-500">
                        Variables are replaced with real order data when messages are sent.
                    </p>
                    <button wire:click="save"
                        wire:loading.attr="disabled"
                        class="btn btn-primary disabled:opacity-50">
                        <span wire:loading.remove wire:target="save">Save Template</span>
                        <span wire:loading wire:target="save">Saving…</span>
                    </button>
                </div>

            </div>
        </div>

    </div>

</div>
