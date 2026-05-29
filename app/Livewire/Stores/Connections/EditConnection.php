<?php

declare(strict_types=1);

namespace App\Livewire\Stores\Connections;

use App\Models\PlatformConnection;
use App\Models\Store;
use App\Services\SyncService;
use Illuminate\Support\Facades\Gate;
use Livewire\Attributes\Layout;
use Livewire\Component;

#[Layout('layouts.app')]
class EditConnection extends Component
{
    public Store $store;
    public PlatformConnection $connection;

    public string $label = '';

    // New values for each credential — empty means "keep current"
    public string $newStoreUrl      = '';
    public string $newConsumerKey   = '';
    public string $newConsumerSecret = '';
    public string $newShopDomain    = '';
    public string $newAccessToken   = '';

    public bool $connectionTested  = false;
    public bool $connectionSuccess = false;
    public string $connectionError = '';

    public function mount(Store $store, PlatformConnection $connection): void
    {
        Gate::authorize('view', $store);
        abort_if($connection->store_id !== $store->id, 404);

        $this->store      = $store;
        $this->connection = $connection;
        $this->label      = $connection->label ?? '';
    }

    public function testConnection(): void
    {
        $this->connection->refresh();
        $result = app(SyncService::class)->testConnection($this->connection);

        $this->connectionTested  = true;
        $this->connectionSuccess = $result;
        $this->connectionError   = $result ? '' : ($this->connection->fresh()->last_sync_error ?? 'Test failed.');
    }

    public function save(): void
    {
        $data = ['label' => $this->label ?: null];

        if ($this->newStoreUrl !== '') {
            $this->validate(['newStoreUrl' => ['required', 'url']]);
            $data['api_url'] = $this->newStoreUrl;
        }

        if ($this->newConsumerKey !== '') {
            $data['consumer_key'] = $this->newConsumerKey;
        }

        if ($this->newConsumerSecret !== '') {
            $data['consumer_secret'] = $this->newConsumerSecret;
        }

        if ($this->newShopDomain !== '') {
            $this->validate(['newShopDomain' => ['required', 'string', 'regex:/^[a-zA-Z0-9-]+\.myshopify\.com$/']]);
            $data['shop_domain'] = $this->newShopDomain;
        }

        if ($this->newAccessToken !== '') {
            $data['access_token'] = $this->newAccessToken;
        }

        $this->connection->update($data);

        $this->newStoreUrl       = '';
        $this->newConsumerKey    = '';
        $this->newConsumerSecret = '';
        $this->newShopDomain     = '';
        $this->newAccessToken    = '';
        $this->connectionTested  = false;
        $this->connectionSuccess = false;

        session()->flash('success', 'Connection updated successfully.');
    }

    public function render(): string
    {
        return <<<'blade'
        <div class="max-w-2xl mx-auto">
            <div class="mb-6 flex items-center justify-between">
                <div>
                    <h2 class="text-lg font-medium text-gray-900">Edit Connection</h2>
                    <p class="mt-1 text-sm text-gray-600 capitalize">
                        {{ $connection->platform }}{{ $connection->label ? ' — ' . $connection->label : '' }}
                    </p>
                </div>
                <a href="{{ route('stores.connections.index', $store) }}"
                   class="text-sm text-gray-600 hover:text-gray-900">← Back</a>
            </div>

            @if (session('success'))
                <div class="mb-4 p-4 bg-green-50 border border-green-200 rounded-md text-sm text-green-700">
                    {{ session('success') }}
                </div>
            @endif

            <div class="space-y-6">

                {{-- Label --}}
                <div>
                    <label class="block text-sm font-medium text-gray-700">Label <span class="font-normal text-gray-400">(optional)</span></label>
                    <input wire:model="label" type="text" placeholder="e.g. Main Store"
                           class="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500 sm:text-sm" />
                </div>

                {{-- Credentials section --}}
                <div class="border-t border-gray-200 pt-5">
                    <p class="text-sm font-medium text-gray-700 mb-1">Credentials</p>
                    <p class="text-xs text-gray-400 mb-4">Leave a field blank to keep the existing value. Only enter a new value to replace it.</p>

                    @if ($connection->platform === 'woocommerce')
                        <div class="space-y-4">
                            <div>
                                <label class="block text-sm font-medium text-gray-700">Store URL</label>
                                <input wire:model="newStoreUrl" type="url"
                                       placeholder="•••••••••• (current URL — enter new to change)"
                                       class="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500 sm:text-sm" />
                                @error('newStoreUrl') <p class="mt-1 text-sm text-red-600">{{ $message }}</p> @enderror
                            </div>
                            <div>
                                <label class="block text-sm font-medium text-gray-700">Consumer Key</label>
                                <input wire:model="newConsumerKey" type="password"
                                       placeholder="•••••••••• (enter new key to replace)"
                                       class="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500 sm:text-sm font-mono" />
                            </div>
                            <div>
                                <label class="block text-sm font-medium text-gray-700">Consumer Secret</label>
                                <input wire:model="newConsumerSecret" type="password"
                                       placeholder="•••••••••• (enter new secret to replace)"
                                       class="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500 sm:text-sm font-mono" />
                            </div>
                        </div>
                    @endif

                    @if ($connection->platform === 'shopify')
                        <div class="space-y-4">
                            <div>
                                <label class="block text-sm font-medium text-gray-700">Shop Domain</label>
                                <input wire:model="newShopDomain" type="text"
                                       placeholder="•••••••••• (enter new domain to replace)"
                                       class="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500 sm:text-sm" />
                                @error('newShopDomain') <p class="mt-1 text-sm text-red-600">{{ $message }}</p> @enderror
                            </div>
                            <div>
                                <label class="block text-sm font-medium text-gray-700">Access Token</label>
                                <input wire:model="newAccessToken" type="password"
                                       placeholder="•••••••••• (enter new token to replace)"
                                       class="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500 sm:text-sm font-mono" />
                            </div>
                        </div>
                    @endif

                    @if ($connection->platform === 'youcan')
                        <div class="space-y-4">
                            <div>
                                <label class="block text-sm font-medium text-gray-700">Store URL</label>
                                <input wire:model="newStoreUrl" type="url"
                                       placeholder="•••••••••• (enter new URL to replace)"
                                       class="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500 sm:text-sm" />
                                @error('newStoreUrl') <p class="mt-1 text-sm text-red-600">{{ $message }}</p> @enderror
                            </div>
                            <div>
                                <label class="block text-sm font-medium text-gray-700">API Token</label>
                                <input wire:model="newAccessToken" type="password"
                                       placeholder="•••••••••• (enter new token to replace)"
                                       class="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500 sm:text-sm font-mono" />
                            </div>
                        </div>
                    @endif
                </div>

                {{-- Test result --}}
                @if ($connectionTested)
                    @if ($connectionSuccess)
                        <div class="p-4 bg-green-50 border border-green-200 rounded-md flex items-center gap-2 text-sm text-green-800">
                            <span>✅</span> Connection is active.
                        </div>
                    @else
                        <div class="p-4 bg-red-50 border border-red-200 rounded-md text-sm text-red-800">
                            <div class="flex items-center gap-2 font-medium"><span>❌</span> Connection failed.</div>
                            @if ($connectionError)
                                <p class="mt-1 text-xs">{{ $connectionError }}</p>
                            @endif
                        </div>
                    @endif
                @endif

                {{-- Actions --}}
                <div class="flex items-center gap-3 pt-2">
                    <button wire:click="testConnection" type="button"
                            wire:loading.attr="disabled"
                            class="px-4 py-2 text-sm font-medium text-white bg-indigo-600 rounded-md hover:bg-indigo-500 disabled:opacity-60">
                        <span wire:loading.remove wire:target="testConnection">Test Connection</span>
                        <span wire:loading wire:target="testConnection">Testing…</span>
                    </button>

                    <button wire:click="save" type="button"
                            wire:loading.attr="disabled"
                            class="px-4 py-2 text-sm font-medium text-white bg-gray-800 rounded-md hover:bg-gray-700 disabled:opacity-60">
                        <span wire:loading.remove wire:target="save">Save</span>
                        <span wire:loading wire:target="save">Saving…</span>
                    </button>
                </div>
            </div>
        </div>
        blade;
    }
}
