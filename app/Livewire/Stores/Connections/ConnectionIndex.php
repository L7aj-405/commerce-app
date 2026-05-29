<?php

declare(strict_types=1);

namespace App\Livewire\Stores\Connections;

use App\Jobs\SyncPlatformOrders;
use App\Models\PlatformConnection;
use App\Models\Store;
use App\Services\SyncService;
use Illuminate\Support\Facades\Gate;
use Livewire\Attributes\Layout;
use Livewire\Component;

#[Layout('layouts.app')]
class ConnectionIndex extends Component
{
    public Store $store;

    public function mount(Store $store): void
    {
        Gate::authorize('view', $store);
        $this->store = $store;
    }

    public function testConnection(string $connectionId): void
    {
        $connection = $this->findConnection($connectionId);
        $result     = app(SyncService::class)->testConnection($connection);

        session()->flash('success', $result ? 'Connection is active.' : 'Connection test failed — status updated to error.');
    }

    public function syncNow(string $connectionId): void
    {
        $connection = $this->findConnection($connectionId);

        if ($connection->is_syncing) {
            session()->flash('error', 'A sync is already in progress for this connection.');
            return;
        }

        $connection->update(['is_syncing' => true]);
        SyncPlatformOrders::dispatch($connection);

        session()->flash('success', 'Sync started in the background.');
    }

    public function disconnect(string $connectionId): void
    {
        $this->findConnection($connectionId)->update(['status' => 'disconnected']);
        session()->flash('success', 'Platform disconnected.');
    }

    private function findConnection(string $id): PlatformConnection
    {
        return $this->store->connections()->findOrFail($id);
    }

    public function render(): string
    {
        return <<<'blade'
        <div>
            <div class="mb-6 flex items-center justify-between">
                <div>
                    <h2 class="text-lg font-medium text-gray-900">Platform Connections</h2>
                    <p class="mt-1 text-sm text-gray-600">Connect your store to external e-commerce platforms.</p>
                </div>
                <a href="{{ route('stores.connections.create', $store) }}"
                   class="px-4 py-2 bg-gray-800 text-white text-sm font-medium rounded-md hover:bg-gray-700">
                    Connect New Platform
                </a>
            </div>

            @if (session('success'))
                <div class="mb-4 p-4 bg-green-50 border border-green-200 rounded-md text-sm text-green-700">
                    {{ session('success') }}
                </div>
            @endif

            @if (session('error'))
                <div class="mb-4 p-4 bg-red-50 border border-red-200 rounded-md text-sm text-red-700">
                    {{ session('error') }}
                </div>
            @endif

            @php
                $connections = $store->connections()->latest()->get();
                $icons = ['woocommerce' => '🛒', 'shopify' => '🛍️', 'youcan' => '🌐'];
                $statusColors = [
                    'active'       => 'bg-green-100 text-green-800',
                    'pending'      => 'bg-yellow-100 text-yellow-800',
                    'error'        => 'bg-red-100 text-red-800',
                    'disconnected' => 'bg-gray-100 text-gray-800',
                ];
            @endphp

            @if ($connections->isEmpty())
                <div class="text-center py-12 border-2 border-dashed border-gray-200 rounded-lg">
                    <p class="text-3xl mb-3">🔌</p>
                    <p class="text-sm font-medium text-gray-700">No platforms connected yet</p>
                    <p class="mt-1 text-sm text-gray-500">Sync products and orders from WooCommerce, Shopify, or YouCan.</p>
                    <a href="{{ route('stores.connections.create', $store) }}"
                       class="mt-4 inline-block px-4 py-2 bg-gray-800 text-white text-sm font-medium rounded-md hover:bg-gray-700">
                        Connect your first platform
                    </a>
                </div>
            @else
                <div class="space-y-4">
                    @foreach ($connections as $connection)
                        <div class="border border-gray-200 rounded-lg p-4">
                            <div class="flex items-start justify-between gap-4">
                                <div class="flex items-center gap-3 min-w-0">
                                    <span class="text-2xl flex-shrink-0" role="img">{{ $icons[$connection->platform] ?? '🔌' }}</span>
                                    <div class="min-w-0">
                                        <div class="flex items-center flex-wrap gap-2">
                                            <span class="font-medium text-gray-900 capitalize">{{ $connection->platform }}</span>
                                            @if ($connection->label)
                                                <span class="text-sm text-gray-500">— {{ $connection->label }}</span>
                                            @endif
                                            <span class="inline-flex items-center px-2 py-0.5 rounded text-xs font-medium {{ $statusColors[$connection->status] ?? 'bg-gray-100 text-gray-800' }}">
                                                {{ ucfirst($connection->status) }}
                                            </span>
                                            @if ($connection->is_syncing)
                                                <span class="inline-flex items-center px-2 py-0.5 rounded text-xs font-medium bg-blue-100 text-blue-800 animate-pulse">
                                                    Syncing…
                                                </span>
                                            @endif
                                        </div>
                                        <div class="mt-1 flex flex-wrap gap-x-4 gap-y-1 text-xs text-gray-500">
                                            <span>Orders synced: {{ number_format($connection->synced_orders_count) }}</span>
                                            @if ($connection->last_synced_at)
                                                <span>Last sync: {{ $connection->last_synced_at->diffForHumans() }}</span>
                                            @else
                                                <span>Never synced</span>
                                            @endif
                                        </div>
                                        @if ($connection->last_sync_error)
                                            <p class="mt-1 text-xs text-red-600 truncate max-w-md" title="{{ $connection->last_sync_error }}">
                                                ⚠ {{ substr($connection->last_sync_error, 0, 80) }}{{ strlen($connection->last_sync_error) > 80 ? '…' : '' }}
                                            </p>
                                        @endif
                                    </div>
                                </div>

                                <div class="flex items-center gap-2 flex-shrink-0">
                                    <button wire:click="testConnection('{{ $connection->id }}')"
                                            wire:loading.attr="disabled"
                                            wire:target="testConnection('{{ $connection->id }}')"
                                            class="px-3 py-1.5 text-xs font-medium text-gray-700 bg-white border border-gray-300 rounded-md hover:bg-gray-50 disabled:opacity-50">
                                        <span wire:loading.remove wire:target="testConnection('{{ $connection->id }}')">Test</span>
                                        <span wire:loading wire:target="testConnection('{{ $connection->id }}')">…</span>
                                    </button>

                                    <button wire:click="syncNow('{{ $connection->id }}')"
                                            wire:loading.attr="disabled"
                                            @disabled($connection->is_syncing)
                                            class="px-3 py-1.5 text-xs font-medium text-gray-700 bg-white border border-gray-300 rounded-md hover:bg-gray-50 disabled:opacity-50 disabled:cursor-not-allowed">
                                        Sync Now
                                    </button>

                                    <a href="{{ route('stores.connections.edit', [$store, $connection]) }}"
                                       class="px-3 py-1.5 text-xs font-medium text-gray-700 bg-white border border-gray-300 rounded-md hover:bg-gray-50">
                                        Edit
                                    </a>

                                    <button wire:click="disconnect('{{ $connection->id }}')"
                                            wire:confirm="Disconnect {{ ucfirst($connection->platform) }}? Syncing will stop."
                                            class="px-3 py-1.5 text-xs font-medium text-red-700 bg-white border border-red-300 rounded-md hover:bg-red-50">
                                        Disconnect
                                    </button>
                                </div>
                            </div>
                        </div>
                    @endforeach
                </div>
            @endif
        </div>
        blade;
    }
}
