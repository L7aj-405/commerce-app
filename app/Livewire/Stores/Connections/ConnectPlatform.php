<?php

declare(strict_types=1);

namespace App\Livewire\Stores\Connections;

use App\Exceptions\ConnectorException;
use App\Jobs\SyncPlatformOrders;
use App\Models\PlatformConnection;
use App\Models\Store;
use App\Services\Connectors\ConnectorFactory;
use Illuminate\Support\Facades\Gate;
use Livewire\Attributes\Layout;
use Livewire\Component;
use Throwable;

#[Layout('layouts.app')]
class ConnectPlatform extends Component
{
    public Store $store;
    public int $step = 1;
    public string $platform = '';

    // Shared
    public string $label = '';

    // WooCommerce + YouCan
    public string $storeUrl = '';

    // WooCommerce only
    public string $consumerKey = '';
    public string $consumerSecret = '';

    // Shopify only
    public string $shopDomain = '';

    // Shopify + YouCan
    public string $accessToken = '';

    // Step 3 test state
    public bool $connectionTested = false;
    public bool $connectionSuccess = false;
    public string $connectionError = '';

    public function mount(Store $store): void
    {
        Gate::authorize('view', $store);
        $this->store = $store;
    }

    public function selectPlatform(string $platform): void
    {
        $this->platform = $platform;
        $this->step     = 2;
    }

    public function backToStep1(): void
    {
        $this->reset(['platform', 'label', 'storeUrl', 'consumerKey', 'consumerSecret', 'shopDomain', 'accessToken', 'connectionTested', 'connectionSuccess', 'connectionError']);
        $this->step = 1;
    }

    public function backToStep2(): void
    {
        $this->connectionTested  = false;
        $this->connectionSuccess = false;
        $this->connectionError   = '';
        $this->step              = 2;
    }

    public function toStep3(): void
    {
        $this->validate($this->credentialRules());
        $this->connectionTested  = false;
        $this->connectionSuccess = false;
        $this->connectionError   = '';
        $this->step              = 3;
    }

    public function testCredentials(): void
    {
        $temp = new PlatformConnection([
            'platform'        => $this->platform,
            'api_url'         => $this->storeUrl ?: null,
            'consumer_key'    => $this->consumerKey ?: null,
            'consumer_secret' => $this->consumerSecret ?: null,
            'access_token'    => $this->accessToken ?: null,
            'shop_domain'     => $this->shopDomain ?: null,
        ]);

        try {
            $this->connectionSuccess = ConnectorFactory::make($temp)->testConnection();
            $this->connectionError   = $this->connectionSuccess ? '' : 'Connection test returned false.';
        } catch (ConnectorException $e) {
            $this->connectionSuccess = false;
            $this->connectionError   = $e->getMessage();
        } catch (Throwable $e) {
            $this->connectionSuccess = false;
            $this->connectionError   = 'Unexpected error: ' . $e->getMessage();
        }

        $this->connectionTested = true;
    }

    public function save(): void
{
    if (! $this->connectionSuccess) {
        return;
    }

    $connection = PlatformConnection::updateOrCreate(
        [
            'store_id' => $this->store->id,
            'platform' => $this->platform,
        ],
        [
            'label' => $this->label ?: null,
            'status' => 'active',
            'api_url' => $this->storeUrl ?: null,
            'consumer_key' => $this->consumerKey ?: null,
            'consumer_secret' => $this->consumerSecret ?: null,
            'access_token' => $this->accessToken ?: null,
            'shop_domain' => $this->shopDomain ?: null,
        ]
    );

    SyncPlatformOrders::dispatch($connection);

    $this->redirect(route('stores.connections.index', $this->store));
}

    private function credentialRules(): array
    {
        return match ($this->platform) {
            'woocommerce' => [
                'storeUrl'       => ['required', 'url'],
                'consumerKey'    => ['required', 'string'],
                'consumerSecret' => ['required', 'string'],
            ],
            'shopify' => [
                'shopDomain'  => ['required', 'string', 'regex:/^[a-zA-Z0-9-]+\.myshopify\.com$/'],
                'accessToken' => ['required', 'string'],
            ],
            'youcan' => [
                'storeUrl'    => ['required', 'url'],
                'accessToken' => ['required', 'string'],
            ],
            default => [],
        };
    }

    public function render(): string
    {
        return <<<'blade'
        <div class="max-w-2xl mx-auto">

            {{-- Progress --}}
            <div class="mb-8">
                <div class="flex items-center gap-2 mb-1">
                    @foreach ([1 => 'Choose Platform', 2 => 'Enter Credentials', 3 => 'Test & Save'] as $n => $label)
                        <div class="flex items-center gap-2 {{ $loop->last ? '' : 'flex-1' }}">
                            <span class="flex-shrink-0 w-6 h-6 rounded-full flex items-center justify-center text-xs font-bold
                                {{ $step >= $n ? 'bg-gray-800 text-white' : 'bg-gray-200 text-gray-500' }}">
                                {{ $n }}
                            </span>
                            <span class="text-xs {{ $step >= $n ? 'text-gray-900 font-medium' : 'text-gray-400' }}">
                                {{ $label }}
                            </span>
                            @if (! $loop->last)
                                <div class="flex-1 h-px {{ $step > $n ? 'bg-gray-800' : 'bg-gray-200' }} mx-2"></div>
                            @endif
                        </div>
                    @endforeach
                </div>
            </div>

            {{-- Step 1: Choose Platform --}}
            @if ($step === 1)
                <div>
                    <h2 class="text-lg font-medium text-gray-900 mb-4">Choose a platform</h2>
                    <div class="grid grid-cols-1 gap-4 sm:grid-cols-3">
                        <button wire:click="selectPlatform('woocommerce')"
                                class="flex flex-col items-center gap-3 p-6 border-2 border-gray-200 rounded-lg hover:border-gray-800 hover:bg-gray-50 transition-colors text-center">
                            <span class="text-4xl" role="img">🛒</span>
                            <div>
                                <p class="font-medium text-gray-900">WooCommerce</p>
                                <p class="mt-1 text-xs text-gray-500">WordPress REST API</p>
                            </div>
                        </button>

                        <button wire:click="selectPlatform('shopify')"
                                class="flex flex-col items-center gap-3 p-6 border-2 border-gray-200 rounded-lg hover:border-gray-800 hover:bg-gray-50 transition-colors text-center">
                            <span class="text-4xl" role="img">🛍️</span>
                            <div>
                                <p class="font-medium text-gray-900">Shopify</p>
                                <p class="mt-1 text-xs text-gray-500">Admin API 2024-01</p>
                            </div>
                        </button>

                        <button wire:click="selectPlatform('youcan')"
                                class="flex flex-col items-center gap-3 p-6 border-2 border-gray-200 rounded-lg hover:border-gray-800 hover:bg-gray-50 transition-colors text-center">
                            <span class="text-4xl" role="img">🌐</span>
                            <div>
                                <p class="font-medium text-gray-900">YouCan</p>
                                <p class="mt-1 text-xs text-gray-500">REST API</p>
                            </div>
                        </button>
                    </div>
                </div>
            @endif

            {{-- Step 2: Enter Credentials --}}
            @if ($step === 2)
                <div>
                    <h2 class="text-lg font-medium text-gray-900 mb-4">
                        Enter credentials
                        <span class="ml-2 text-base">{{ ['woocommerce' => '🛒', 'shopify' => '🛍️', 'youcan' => '🌐'][$platform] ?? '' }}</span>
                        <span class="capitalize text-gray-500 font-normal text-base">{{ $platform }}</span>
                    </h2>

                    <div class="space-y-4">
                        <div>
                            <label class="block text-sm font-medium text-gray-700">Label <span class="font-normal text-gray-400">(optional)</span></label>
                            <input wire:model="label" type="text" placeholder="e.g. Main Store"
                                   class="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500 sm:text-sm" />
                        </div>

                        @if ($platform === 'woocommerce')
                            <div>
                                <label class="block text-sm font-medium text-gray-700">Store URL</label>
                                <input wire:model="storeUrl" type="url" placeholder="https://mystore.com"
                                       class="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500 sm:text-sm" />
                                @error('storeUrl') <p class="mt-1 text-sm text-red-600">{{ $message }}</p> @enderror
                            </div>
                            <div>
                                <label class="block text-sm font-medium text-gray-700">Consumer Key</label>
                                <input wire:model="consumerKey" type="text" placeholder="ck_••••••••••••••••••••••••••••••••••••••••"
                                       class="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500 sm:text-sm font-mono" />
                                @error('consumerKey') <p class="mt-1 text-sm text-red-600">{{ $message }}</p> @enderror
                            </div>
                            <div>
                                <label class="block text-sm font-medium text-gray-700">Consumer Secret</label>
                                <input wire:model="consumerSecret" type="password" placeholder="cs_••••••••••••••••••••••••••••••••••••••••"
                                       class="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500 sm:text-sm font-mono" />
                                @error('consumerSecret') <p class="mt-1 text-sm text-red-600">{{ $message }}</p> @enderror
                            </div>
                        @endif

                        @if ($platform === 'shopify')
                            <div>
                                <label class="block text-sm font-medium text-gray-700">Shop Domain</label>
                                <input wire:model="shopDomain" type="text" placeholder="myshop.myshopify.com"
                                       class="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500 sm:text-sm" />
                                <p class="mt-1 text-xs text-gray-400">Must end in .myshopify.com</p>
                                @error('shopDomain') <p class="mt-1 text-sm text-red-600">{{ $message }}</p> @enderror
                            </div>
                            <div>
                                <label class="block text-sm font-medium text-gray-700">Access Token</label>
                                <input wire:model="accessToken" type="password" placeholder="shpat_••••••••••••••••••••••••••••••••"
                                       class="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500 sm:text-sm font-mono" />
                                @error('accessToken') <p class="mt-1 text-sm text-red-600">{{ $message }}</p> @enderror
                            </div>
                        @endif

                        @if ($platform === 'youcan')
                            <div>
                                <label class="block text-sm font-medium text-gray-700">Store URL</label>
                                <input wire:model="storeUrl" type="url" placeholder="https://mystore.youcan.shop"
                                       class="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500 sm:text-sm" />
                                @error('storeUrl') <p class="mt-1 text-sm text-red-600">{{ $message }}</p> @enderror
                            </div>
                            <div>
                                <label class="block text-sm font-medium text-gray-700">API Token</label>
                                <input wire:model="accessToken" type="password" placeholder="Your YouCan API token"
                                       class="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500 sm:text-sm font-mono" />
                                @error('accessToken') <p class="mt-1 text-sm text-red-600">{{ $message }}</p> @enderror
                            </div>
                        @endif
                    </div>

                    <div class="mt-6 flex items-center gap-3">
                        <button wire:click="backToStep1" type="button"
                                class="px-4 py-2 text-sm font-medium text-gray-700 bg-white border border-gray-300 rounded-md hover:bg-gray-50">
                            ← Back
                        </button>
                        <button wire:click="toStep3" type="button"
                                class="px-4 py-2 text-sm font-medium text-white bg-gray-800 rounded-md hover:bg-gray-700">
                            Next: Test Connection →
                        </button>
                    </div>
                </div>
            @endif

            {{-- Step 3: Test & Save --}}
            @if ($step === 3)
                <div>
                    <h2 class="text-lg font-medium text-gray-900 mb-4">Test & save</h2>

                    <div class="mb-6 p-4 bg-gray-50 border border-gray-200 rounded-lg text-sm space-y-1">
                        <div class="flex gap-2"><span class="text-gray-500 w-20">Platform</span><span class="font-medium capitalize text-gray-900">{{ $platform }}</span></div>
                        @if ($label)
                            <div class="flex gap-2"><span class="text-gray-500 w-20">Label</span><span class="font-medium text-gray-900">{{ $label }}</span></div>
                        @endif
                    </div>

                    @if ($connectionTested)
                        @if ($connectionSuccess)
                            <div class="mb-4 p-4 bg-green-50 border border-green-200 rounded-md flex items-center gap-2 text-sm text-green-800">
                                <span>✅</span>
                                <span>Connection successful! You can now save.</span>
                            </div>
                        @else
                            <div class="mb-4 p-4 bg-red-50 border border-red-200 rounded-md text-sm text-red-800">
                                <div class="flex items-center gap-2 font-medium"><span>❌</span> Connection failed.</div>
                                @if ($connectionError)
                                    <p class="mt-1 text-xs">{{ $connectionError }}</p>
                                @endif
                                <p class="mt-2 text-xs text-red-600">Go back and check your credentials.</p>
                            </div>
                        @endif
                    @else
                        <p class="mb-4 text-sm text-gray-600">Click <strong>Test Connection</strong> to verify your credentials before saving.</p>
                    @endif

                    <div class="flex items-center gap-3">
                        <button wire:click="backToStep2" type="button"
                                class="px-4 py-2 text-sm font-medium text-gray-700 bg-white border border-gray-300 rounded-md hover:bg-gray-50">
                            ← Back
                        </button>

                        <button wire:click="testCredentials" type="button"
                                wire:loading.attr="disabled"
                                class="px-4 py-2 text-sm font-medium text-white bg-indigo-600 rounded-md hover:bg-indigo-500 disabled:opacity-60">
                            <span wire:loading.remove wire:target="testCredentials">Test Connection</span>
                            <span wire:loading wire:target="testCredentials">Testing…</span>
                        </button>

                        @if ($connectionTested && $connectionSuccess)
                            <button wire:click="save" type="button"
                                    wire:loading.attr="disabled"
                                    class="px-4 py-2 text-sm font-medium text-white bg-gray-800 rounded-md hover:bg-gray-700 disabled:opacity-60">
                                <span wire:loading.remove wire:target="save">Save & Start Sync</span>
                                <span wire:loading wire:target="save">Saving…</span>
                            </button>
                        @endif
                    </div>
                </div>
            @endif
        </div>
        blade;
    }
}
