<?php

declare(strict_types=1);

namespace App\Livewire\Products;

use App\Models\Product;
use App\Models\Store;
use App\Models\SyncLog;
use App\Services\Products\ProductService;
use App\Services\Sync\ProductPushService;
use Livewire\Component;
use Livewire\Attributes\Layout;

#[Layout('layouts.app')]
class ProductEdit extends Component
{
    public Store $store;
    public Product $product;

    public string $name;
    public string $sku;
    public string $type;
    public string $status;
    public float $price;
    public float $cost;
    public string $description;

    protected $rules = [
        'name'   => 'required|string|min:3',
        'sku'    => 'required|string',
        'type'   => 'required|in:simple,variable',
        'price'  => 'required|numeric|min:0',
        'cost'   => 'required|numeric|min:0',
    ];

    protected $messages = [
        'name.required' => 'Product name is required',
        'sku.required'  => 'SKU is required',
    ];

    public function mount(Store $store, Product $product): void
    {
        $this->store   = $store;
        $this->product = $product;

        $this->name        = $product->name;
        $this->sku         = $product->sku;
        $this->type        = $product->type;
        $this->status      = $product->status;
        $this->price       = (float) $product->price;
        $this->cost        = (float) $product->cost;
        $this->description = $product->description ?? '';
    }

    public function save(): void
    {
        $this->validate();

        try {
            $oldType = $this->product->type;

            $service = new ProductService();
            $service->update($this->product, [
                'name'        => $this->name,
                'sku'         => $this->sku,
                'type'        => $this->type,
                'status'      => $this->status,
                'price'       => $this->price,
                'cost'        => $this->cost,
                'description' => $this->description,
            ]);

            $this->product->refresh();
            $typeChanged = $oldType !== $this->product->type;

            // Auto-push to platform whenever the product is already synced
            if (!empty($this->product->external_id)) {
                $pushService = app(ProductPushService::class);
                $results     = $pushService->pushProduct($this->product);

                $synced = collect($results)->where('success', true)->pluck('platform')->map(fn ($p) => ucfirst((string) ($p ?? '')));
                $failed = collect($results)->where('success', false)->pluck('platform')->map(fn ($p) => ucfirst((string) ($p ?? '')));

                $base    = $typeChanged ? 'Type changed to ' . ucfirst($this->type) . ', saved' : 'Product saved';
                $message = $synced->isNotEmpty()
                    ? "{$base} and synced to {$synced->join(', ')}"
                    : ($failed->isNotEmpty() ? "{$base} — platform sync failed" : $base);

                $this->dispatch('notify', message: $message, type: $failed->isEmpty() ? 'success' : 'warning');
            } else {
                $this->dispatch('notify', message: 'Product updated successfully');
            }
        } catch (\Exception $e) {
            $this->dispatch('notify', message: 'Error updating product: ' . $e->getMessage(), type: 'error');
        }
    }

    /**
     * Push (or create) this product on a specific platform connection.
     * Routes to createProduct when the product has no external_id yet.
     */
    public function pushToPlatform(string $connectionId): void
    {
        $connection = $this->store->connections()
            ->where('id', $connectionId)
            ->where('status', 'active')
            ->first();

        if ($connection === null) {
            $this->dispatch('notify', message: 'Connection not found or inactive', type: 'error');
            return;
        }

        if ($this->product->isVariable() && !$this->product->variants()->exists()) {
            $this->dispatch('notify', message: 'Add at least one variant before pushing a variable product', type: 'warning');
            return;
        }

        try {
            $pushService = app(ProductPushService::class);
            $isNew       = empty($this->product->external_id);

            $results = $isNew
                ? $pushService->createProduct($this->product, $connection->platform)
                : $pushService->pushProduct($this->product, $connection->platform);

            $this->product->refresh();

            $success = collect($results)->every(fn ($r) => $r['success']);

            if ($success && $isNew && $this->product->isVariable()) {
                $variantCount = $this->product->variants()->count();
                $message      = "Product created on " . ucfirst($connection->platform) . " with {$variantCount} variant(s)";
            } elseif ($success) {
                $verb    = $isNew ? 'created on' : 'updated on';
                $message = "Product {$verb} " . ucfirst($connection->platform);
            } else {
                $message = collect($results)->pluck('message')->join(', ');
            }

            $this->dispatch('notify', message: $message, type: $success ? 'success' : 'error');
        } catch (\Exception $e) {
            $this->dispatch('notify', message: 'Push failed: ' . $e->getMessage(), type: 'error');
        }
    }

    /**
     * Push (or create) this product on all active platform connections.
     * Routes to createProduct when the product has no external_id yet.
     */
    public function pushToAllPlatforms(): void
    {
        if ($this->product->isVariable() && !$this->product->variants()->exists()) {
            $this->dispatch('notify', message: 'Add at least one variant before pushing a variable product', type: 'warning');
            return;
        }

        try {
            $pushService = app(ProductPushService::class);
            $isNew       = empty($this->product->external_id);

            $results = $isNew
                ? $pushService->createProduct($this->product)
                : $pushService->pushProduct($this->product);

            $this->product->refresh();

            $synced = collect($results)->where('success', true)->pluck('platform')->map(fn ($p) => ucfirst((string) ($p ?? '')));
            $failed = collect($results)->where('success', false)->pluck('platform')->map(fn ($p) => ucfirst((string) ($p ?? '')));
            $count  = $synced->count();

            if ($synced->isNotEmpty()) {
                $verb    = $isNew ? 'Created on' : 'Pushed to';
                $suffix  = $isNew && $this->product->isVariable()
                    ? ' with ' . $this->product->variants()->count() . ' variant(s)'
                    : '';
                $message = "{$verb} {$count} platform" . ($count !== 1 ? 's' : '') . "{$suffix}: {$synced->join(', ')}";
            } else {
                $message = 'Push failed: ' . collect($results)->pluck('message')->join(', ');
            }

            $this->dispatch('notify', message: $message, type: $failed->isEmpty() ? 'success' : ($synced->isEmpty() ? 'error' : 'warning'));
        } catch (\Exception $e) {
            $this->dispatch('notify', message: 'Push failed: ' . $e->getMessage(), type: 'error');
        }
    }

    public function getVariants()
    {
        return $this->product->variants()->with('stocks')->get();
    }

    public function getVariantStats(): array
    {
        return ['total' => $this->product->variants()->count()];
    }

    public function render()
    {
        // Connections that match this product's platform (or all active if no platform set)
        $platformConnections = $this->store->connections()
            ->where('status', 'active')
            ->when($this->product->platform, fn ($q) => $q->where('platform', $this->product->platform))
            ->get();

        // Last push log per connection
        $lastPushLogs = [];
        foreach ($platformConnections as $connection) {
            $lastPushLogs[$connection->id] = SyncLog::where('platform_connection_id', $connection->id)
                ->where('direction', 'push')
                ->where('action', 'product')
                ->where('entity_id', $this->product->id)
                ->latest('completed_at')
                ->first();
        }

        return view('livewire.products.product-edit', [
            'warehouses'          => $this->store->warehouses()->orderBy('name')->get(),
            'variantCount'        => $this->product->variants()->count(),
            'platformConnections' => $platformConnections,
            'lastPushLogs'        => $lastPushLogs,
        ]);
    }
}
