<?php

declare(strict_types=1);

namespace App\Livewire\Products;

use App\Enums\StockMovementType;
use App\Models\Product;
use App\Models\Stock;
use App\Models\StockMovement;
use App\Models\Warehouse;
use App\Services\Stocks\StockService;
use App\Services\Sync\ProductPushService;
use App\Services\Sync\VariantPushService;
use Illuminate\Support\Facades\Log;
use Livewire\Attributes\Layout;
use Livewire\Component;

#[Layout('layouts.app')]
class ProductStock extends Component
{
    public Product $product;

    /** [stock_id => qty_string] — bound to the quantity inputs via wire:model.defer */
    public array $quantities = [];

    public bool $showAdjustForm = false;
    public string $selectedWarehouse = '';
    public array $selectedVariants = [];
    public string $adjustQty = '';
    public string $adjustNotes = '';

    public function mount(Product $product): void
    {
        $this->product = $product;
        $this->loadStocks();
    }

    /**
     * Reload quantities from the database.
     * Called after every write so the UI always reflects persisted values.
     */
    private function loadStocks(): void
    {
        foreach ($this->getStockQuery()->get() as $stock) {
            $this->quantities[$stock->id] = (string) $stock->quantity;
        }
    }

    private function getStockQuery()
    {
        $query = $this->product->stocks()->with(['warehouse', 'variant']);

        return $this->product->isVariable()
            ? $query->whereNotNull('variant_id')
            : $query->whereNull('variant_id');
    }

    /**
     * Save a quantity change and push to connected platforms.
     *
     * Root-cause fix: we work on the specific $stock row already loaded instead
     * of delegating to StockService::adjustStock(), which queries by
     * (product_id, warehouse_id) and returns a random variant stock on
     * variable products that have multiple rows for the same warehouse.
     */
    public function updateStock(
        string $stockId,
        ProductPushService $pushService,
        VariantPushService $variantPushService
    ): void {
        $stock  = Stock::with(['product', 'warehouse', 'variant'])->findOrFail($stockId);
        $newQty = (int) ($this->quantities[$stockId] ?? 0);
        $oldQty = $stock->quantity;

        if ($newQty === $oldQty) {
            $this->dispatch('notify', message: 'No changes to save', type: 'info');
            return;
        }

        try {
            $stock->update(['quantity' => $newQty]);

            StockMovement::create([
                'warehouse_id' => $stock->warehouse_id,
                'product_id'   => $stock->product_id,
                'variant_id'   => $stock->variant_id,
                'user_id'      => auth()->id(),
                'type'         => StockMovementType::Adjustment->value,
                'quantity'     => $newQty - $oldQty,
                'notes'        => "Manual adjustment: {$oldQty} → {$newQty}",
            ]);

            // Push to platform — per-variant for variable products, per-product for simple
            if ($stock->variant_id !== null && $stock->variant !== null) {
                $results = $variantPushService->pushVariantStock($stock->variant);
            } else {
                $results = $pushService->pushStock($this->product);
            }

            $synced = collect($results)->where('success', true)->pluck('platform')->map(fn ($p) => ucfirst((string) ($p ?? '')));
            $failed = collect($results)->where('success', false)->pluck('platform')->map(fn ($p) => ucfirst((string) ($p ?? '')));

            $this->loadStocks();

            $message = $synced->isNotEmpty()
                ? "Stock updated ({$oldQty} → {$newQty}) and synced to: {$synced->join(', ')}"
                : ($failed->isNotEmpty() ? "Stock updated ({$oldQty} → {$newQty}) — platform sync failed" : "Stock updated: {$oldQty} → {$newQty}");

            $this->dispatch('notify', message: $message, type: $failed->isEmpty() ? 'success' : 'warning');
        } catch (\Throwable $e) {
            $this->loadStocks();
            Log::warning('ProductStock::updateStock failed', ['stock' => $stockId, 'error' => $e->getMessage()]);
            $this->dispatch('notify', message: 'Error: ' . $e->getMessage(), type: 'error');
        }
    }

    /**
     * Add stock to a specific warehouse for one or more variants simultaneously.
     * For simple products the adjustment goes to the product-level stock row.
     */
    public function adjustStockAll(
        StockService $service,
        ProductPushService $pushService,
        VariantPushService $variantPushService
    ): void {
        $rules = [
            'selectedWarehouse' => 'required|string',
            'adjustQty'         => 'required|numeric|min:1',
        ];

        if ($this->product->isVariable()) {
            $rules['selectedVariants']   = 'required|array|min:1';
            $rules['selectedVariants.*'] = 'string';
        }

        $this->validate($rules, [
            'selectedVariants.required' => 'Please select at least one variant.',
            'selectedVariants.min'      => 'Please select at least one variant.',
        ]);

        try {
            $warehouse = Warehouse::find($this->selectedWarehouse);

            if (!$warehouse) {
                $this->dispatch('notify', message: 'Warehouse not found', type: 'error');
                return;
            }

            $qty   = (int) $this->adjustQty;
            $notes = $this->adjustNotes ?: 'Manual adjustment';

            if (!$this->product->isVariable()) {
                // Simple product — single stock row
                $service->addStock($this->product, $warehouse, $qty, $notes);
                $results = $pushService->pushStock($this->product);

                $synced = collect($results)->where('success', true)->pluck('platform')->map(fn ($p) => ucfirst((string) ($p ?? '')));
                $failed = collect($results)->where('success', false)->pluck('platform')->map(fn ($p) => ucfirst((string) ($p ?? '')));

                $this->resetForm();
                $this->loadStocks();

                $message = $synced->isNotEmpty()
                    ? "Stock adjusted and synced to: {$synced->join(', ')}"
                    : 'Stock adjusted in SaaS (platform sync failed)';

                $this->dispatch('notify', message: $message, type: $failed->isEmpty() ? 'success' : 'warning');
                return;
            }

            // Variable product — apply to each selected variant
            $variants = $this->product->variants()->whereIn('id', $this->selectedVariants)->get();

            $allSynced = collect();
            $allFailed = collect();
            $names     = [];

            foreach ($variants as $variant) {
                $service->addStock($this->product, $warehouse, $qty, $notes, $variant);

                $results    = $variantPushService->pushVariantStock($variant);
                $allSynced  = $allSynced->merge(collect($results)->where('success', true)->pluck('platform'));
                $allFailed  = $allFailed->merge(collect($results)->where('success', false)->pluck('platform'));
                $names[]    = $variant->getDisplayName();
            }

            $count       = count($names);
            $nameList    = implode(', ', $names);
            $syncedNames = $allSynced->unique()->map(fn ($p) => ucfirst((string) ($p ?? '')));
            $failedNames = $allFailed->unique()->map(fn ($p) => ucfirst((string) ($p ?? '')));

            if ($syncedNames->isNotEmpty()) {
                Log::info('Stock pushed after multi-variant adjustment', [
                    'product'   => $this->product->id,
                    'variants'  => $this->selectedVariants,
                    'platforms' => $syncedNames->all(),
                ]);
            }

            $this->resetForm();
            $this->loadStocks();

            $label   = "Added {$qty} to {$count} variant" . ($count !== 1 ? 's' : '') . " ({$nameList})";
            $message = $syncedNames->isNotEmpty()
                ? "{$label} and synced to: {$syncedNames->join(', ')}"
                : ($failedNames->isNotEmpty() ? "{$label} — platform sync failed" : $label);

            $this->dispatch('notify', message: $message, type: $failedNames->isEmpty() ? 'success' : 'warning');
        } catch (\Throwable $e) {
            $this->dispatch('notify', message: 'Error: ' . $e->getMessage(), type: 'error');
        }
    }

    public function resetForm(): void
    {
        $this->showAdjustForm    = false;
        $this->selectedWarehouse = '';
        $this->selectedVariants  = [];
        $this->adjustQty         = '';
        $this->adjustNotes       = '';
    }

    public function render()
    {
        return view('livewire.products.product-stock', [
            'stocks'     => $this->getStockQuery()->get(),
            'warehouses' => $this->product->store->warehouses()->get(),
            'variants'   => $this->product->isVariable() ? $this->product->variants()->get() : collect(),
        ]);
    }
}
