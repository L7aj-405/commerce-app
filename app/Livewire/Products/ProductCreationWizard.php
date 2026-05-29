<?php

declare(strict_types=1);

namespace App\Livewire\Products;

use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\Stock;
use App\Models\StockMovement;
use App\Models\Store;
use App\Services\Sync\ProductPushService;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Layout;
use Livewire\Component;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

#[Layout('layouts.app')]
class ProductCreationWizard extends Component
{
    public Store $store;

    // ── Wizard ──────────────────────────────────────────────
    public int     $step        = 1;
    public ?string $productId   = null;

    // ── Step 1: Type ────────────────────────────────────────
    public string $productType = 'simple';

    // ── Step 2: Basic Info ──────────────────────────────────
    public string $name        = '';
    public string $sku         = '';
    public string $description = '';

    // ── Step 3 (simple): Pricing ────────────────────────────
    public string $price        = '';
    public string $cost         = '';
    public string $comparePrice = '';

    // ── Step 4 (simple): Stock ──────────────────────────────
    public string $warehouseId = '';
    public int    $quantity    = 0;

    // ── Step 3 (variable): Attributes ───────────────────────
    public array  $productAttributes = [];
    public string $newAttrName       = '';
    public array  $newAttrValueInputs = [];

    // ── Step 4 (variable): Variant combos ───────────────────
    public array  $variants = [];

    // ── Step 5 (variable): Bulk controls ────────────────────
    public string $baseQty = '0';

    // ── Save state ──────────────────────────────────────────
    public string  $saveStatus  = 'unsaved';
    public ?string $lastSavedAt = null;

    // ── Push modal ──────────────────────────────────────────
    public bool  $showPushModal        = false;
    public array $selectedConnectionIds = [];
    public array $pushResults          = [];
    public bool  $isPushing            = false;
    public bool  $pushDone             = false;

    // ────────────────────────────────────────────────────────
    // Mount
    // ────────────────────────────────────────────────────────

    public function mount(Store $store): void
    {
        $this->store = $store;

        $warehouse = $store->getPrimaryWarehouse()
            ?? Auth::user()->warehouses()->where('is_active', true)->first();

        if ($warehouse) {
            $this->warehouseId = $warehouse->id;
        }
    }

    // ────────────────────────────────────────────────────────
    // Computed
    // ────────────────────────────────────────────────────────


    // ────────────────────────────────────────────────────────
    // Navigation
    // ────────────────────────────────────────────────────────

    public function nextStep(): void
    {
        $this->resetErrorBag();

        if (!$this->validateCurrentStep()) {
            return;
        }

        if (!empty(trim($this->name))) {
            $this->autosave();
        }

        $this->step = min($this->step + 1, $this->productType === 'variable' ? 6 : 5);
    }

    public function prevStep(): void
    {
        $this->resetErrorBag();
        $this->step = max($this->step - 1, 1);
    }

    public function goToStep(int $target): void
    {
        if ($target < $this->step) {
            $this->resetErrorBag();
            $this->step = $target;
        }
    }

    protected function validateCurrentStep(): bool
    {
        try {
            match (true) {
                $this->step === 2 => $this->validate([
                    'name' => 'required|string|max:255',
                    'sku'  => 'required|string|max:255',
                ], [
                    'name.required' => 'Product name is required.',
                    'sku.required'  => 'SKU is required.',
                ]),

                $this->step === 3 && $this->productType === 'simple' => $this->validate([
                    'price' => 'required|numeric|min:0',
                    'cost'  => 'required|numeric|min:0',
                ], [
                    'price.required' => 'Sale price is required.',
                    'cost.required'  => 'Cost price is required.',
                ]),

                $this->step === 3 && $this->productType === 'variable' => $this->validateAttributes(),

                $this->step === 4 && $this->productType === 'variable' => $this->validateVariants(),

                default => true,
            };
        } catch (ValidationException) {
            return false;
        }

        return !$this->getErrorBag()->isNotEmpty();
    }

    protected function validateAttributes(): void
    {
        if (empty($this->productAttributes)) {
            $this->addError('newAttrName', 'Add at least one attribute (e.g. Color, Size).');
            return;
        }
        foreach ($this->productAttributes as $i => $attr) {
            if (empty($attr['values'])) {
                $this->addError("attr_{$i}_value", "Add at least one value for \"{$attr['name']}\".");
            }
        }
    }

    protected function validateVariants(): void
    {
        if (empty($this->variants)) {
            $this->addError('variants', 'No variants generated. Add attributes with values in the previous step.');
        }
        if (count(array_filter($this->variants, fn ($v) => $v['selected'] ?? true)) === 0) {
            $this->addError('variants', 'Select at least one variant to include.');
        }
    }

    // ────────────────────────────────────────────────────────
    // Auto-save
    // ────────────────────────────────────────────────────────

    public function autosave(): void
    {
        if (empty(trim($this->name))) return;

        $this->saveStatus = 'saving';

        try {
            $data = [
                'name'          => $this->name,
                'sku'           => $this->sku ?: null,
                'type'          => $this->productType,
                'description'   => $this->description,
                'price'         => (float) ($this->price ?: 0),
                'cost'          => (float) ($this->cost ?: 0),
                'compare_price' => $this->comparePrice ? (float) $this->comparePrice : null,
                'status'        => 'draft',
            ];

            if ($this->productId) {
                Product::where('id', $this->productId)->update($data);
            } else {
                $product = Product::create(array_merge($data, ['store_id' => $this->store->id]));
                $this->productId = $product->id;
            }

            $this->saveStatus  = 'saved';
            $this->lastSavedAt = now()->format('H:i:s');
        } catch (\Throwable) {
            $this->saveStatus = 'error';
        }
    }

    public function updatedProductType(): void
    {
        $this->productAttributes = [];
        $this->variants   = [];
    }

    public function updatedName(): void        { $this->autosave(); }
    public function updatedSku(): void         { $this->autosave(); }
    public function updatedDescription(): void { $this->autosave(); }
    public function updatedPrice(): void       { $this->autosave(); }
    public function updatedCost(): void        { $this->autosave(); }
    public function updatedComparePrice(): void{ $this->autosave(); }

    public function generateSku(): void
    {
        if (empty(trim($this->name))) return;
        $this->sku = strtoupper(Str::slug(Str::words($this->name, 3, ''), '-')) . '-' . strtoupper(Str::random(4));
        $this->autosave();
    }

    // ────────────────────────────────────────────────────────
    // Attributes (variable)
    // ────────────────────────────────────────────────────────

    public function addAttribute(): void
    {
        $name = trim($this->newAttrName);
        if (empty($name)) return;

        foreach ($this->productAttributes as $a) {
            if (strtolower($a['name']) === strtolower($name)) return;
        }

        $this->productAttributes[] = ['name' => $name, 'values' => []];
        $this->newAttrName  = '';
        $this->regenerateVariants();
    }

    public function removeAttribute(int $index): void
    {
        unset($this->productAttributes[$index]);
        $this->productAttributes = array_values($this->productAttributes);
        $this->regenerateVariants();
    }

    public function addAttributeValue(int $attrIndex): void
    {
        $value = trim($this->newAttrValueInputs[$attrIndex] ?? '');
        if (empty($value)) return;

        if (!in_array($value, $this->productAttributes[$attrIndex]['values'] ?? [], true)) {
            $this->productAttributes[$attrIndex]['values'][] = $value;
        }

        $this->newAttrValueInputs[$attrIndex] = '';
        $this->regenerateVariants();
    }

    public function removeAttributeValue(int $attrIndex, int $valueIndex): void
    {
        unset($this->productAttributes[$attrIndex]['values'][$valueIndex]);
        $this->productAttributes[$attrIndex]['values'] = array_values($this->productAttributes[$attrIndex]['values']);
        $this->regenerateVariants();
    }

    // ────────────────────────────────────────────────────────
    // Variant generation
    // ────────────────────────────────────────────────────────

    public function regenerateVariants(): void
    {
        $active = array_values(array_filter($this->productAttributes, fn ($a) => !empty($a['values'])));

        if (empty($active)) {
            $this->variants = [];
            return;
        }

        $combinations = $this->cartesian($active);

        $existing = [];
        foreach ($this->variants as $v) {
            $existing[$v['combo_key']] = $v;
        }

        $this->variants = array_map(function (array $combo) use ($existing): array {
            $comboKey  = implode('|', array_values($combo));
            $prev      = $existing[$comboKey] ?? null;
            $skuSuffix = strtoupper(implode('', array_map(
                fn ($v) => substr(preg_replace('/[^a-z0-9]/i', '', $v), 0, 3),
                array_values($combo)
            )));

            return [
                'combo_key'  => $comboKey,
                'name'       => implode(' / ', array_values($combo)),
                'attributes' => $combo,
                'sku'        => $prev['sku']      ?? ($this->sku ? $this->sku . '-' . $skuSuffix : ''),
                'price'      => $prev['price']    ?? $this->price,
                'cost'       => $prev['cost']     ?? $this->cost,
                'qty'        => $prev['qty']      ?? 0,
                'selected'   => $prev['selected'] ?? true,
            ];
        }, $combinations);
    }

    protected function cartesian(array $attrs): array
    {
        if (empty($attrs)) return [[]];

        $attr   = array_shift($attrs);
        $rest   = $this->cartesian($attrs);
        $result = [];

        foreach ($attr['values'] as $value) {
            foreach ($rest as $combo) {
                $result[] = array_merge([$attr['name'] => $value], $combo);
            }
        }

        return $result;
    }

    public function applyBasePriceToAll(): void
    {
        foreach ($this->variants as $i => $_) {
            $this->variants[$i]['price'] = $this->price;
            $this->variants[$i]['cost']  = $this->cost;
        }
    }

    public function applyBaseQtyToAll(): void
    {
        $qty = (int) $this->baseQty;
        foreach ($this->variants as $i => $_) {
            $this->variants[$i]['qty'] = $qty;
        }
    }

    // ────────────────────────────────────────────────────────
    // Finalize & Push
    // ────────────────────────────────────────────────────────

    public function saveAsDraft(): void
    {
        $this->finalize('draft');
        session()->flash('success', 'Product saved as draft.');
        $this->redirect(route('stores.products.edit', [$this->store, $this->productId]));
    }

    public function openPushModal(): void
    {
        // autosave() keeps the product stub in sync; variants are persisted by confirmPush()->finalize()
        $this->autosave();

        $connections = $this->store->connections()->where('status', 'active')->get();
        $this->selectedConnectionIds = $connections->pluck('id')->toArray();
        $this->pushResults           = [];
        $this->pushDone              = false;
        $this->isPushing             = false;
        $this->showPushModal         = true;
    }

    public function closePushModal(): void
    {
        if ($this->pushDone) {
            $this->redirect(route('stores.products.edit', [$this->store, $this->productId]));
            return;
        }
        $this->showPushModal = false;
    }

    public function confirmPush(): void
    {
        $this->isPushing   = true;
        $this->pushResults = [];

        try {
            // Save product + variants/stock as 'active' so connectors send the correct status to the platform
            $this->finalize('active');

            $product = Product::with(['variants', 'stocks', 'store'])->findOrFail($this->productId);

            // Guard (should always pass after finalize('active') but catches edge cases)
            if ($product->status !== 'active') {
                $this->pushResults[] = [
                    'success'  => false,
                    'platform' => 'system',
                    'message'  => 'Product must be published before pushing to platforms.',
                ];
                $this->isPushing = false;
                return;
            }

            if ($product->isVariable() && !$product->variants()->exists()) {
                $this->pushResults[] = [
                    'success'  => false,
                    'platform' => 'system',
                    'message'  => 'Add at least one variant before pushing a variable product.',
                ];
                $this->isPushing = false;
                return;
            }

            $service = app(ProductPushService::class);

            $connections = $this->store->connections()
                ->whereIn('id', $this->selectedConnectionIds)
                ->where('status', 'active')
                ->get();

            if ($connections->isEmpty()) {
                $connections = $this->store->connections()->where('status', 'active')->get();
            }

            // Push once per unique platform; refresh so external_id propagates between platforms
            foreach ($connections->groupBy('platform') as $platform => $platformConnections) {
                $results = $service->createProduct($product, $platform);
                $product->refresh();

                foreach ($results as $r) {
                    $this->pushResults[] = array_merge($r, ['platform' => $platform]);
                }
            }

            $succeeded = collect($this->pushResults)->where('success', true)->count();
            $total     = count($this->pushResults);

            if ($succeeded > 0) {
                // At least one platform succeeded — redirect with flash
                session()->flash('success', $succeeded === $total
                    ? "Product published on {$total} " . Str::plural('platform', $total) . '.'
                    : "Product published on {$succeeded}/{$total} " . Str::plural('platform', $total) . '.'
                );
                $this->redirect(route('stores.products.index', $this->store));
                return;
            }

            // All platforms failed — stay on page so the user can see the errors and retry
            $this->pushDone = true;

        } catch (\Throwable $e) {
            $this->pushResults[] = [
                'success'  => false,
                'platform' => 'system',
                'message'  => $e->getMessage(),
            ];
            $this->pushDone = true;
        }

        $this->isPushing = false;
    }

    protected function finalize(string $status): void
    {
        DB::transaction(function () use ($status): void {
            $productData = [
                'name'          => $this->name,
                'sku'           => $this->sku ?: null,
                'type'          => $this->productType,
                'description'   => $this->description,
                'price'         => (float) $this->price,
                'cost'          => (float) $this->cost,
                'compare_price' => $this->comparePrice ? (float) $this->comparePrice : null,
                'status'        => $status,
            ];

            if ($this->productId) {
                Product::where('id', $this->productId)->update($productData);
            } else {
                $product = Product::create(array_merge($productData, ['store_id' => $this->store->id]));
                $this->productId = $product->id;
            }

            $product = Product::findOrFail($this->productId);

            if ($this->productType === 'simple') {
                if ($this->warehouseId) {
                    Stock::updateOrCreate(
                        ['product_id' => $product->id, 'warehouse_id' => $this->warehouseId, 'variant_id' => null],
                        ['quantity' => $this->quantity, 'reorder_level' => 10]
                    );
                }
            } else {
                // Force-delete all variants (including soft-deleted) so their SKUs are freed in the
                // unique index before we re-create. Soft-delete alone keeps the row (and its SKU)
                // in the index, causing a 1062 on the second finalize() call.
                $oldIds = ProductVariant::withTrashed()->where('product_id', $product->id)->pluck('id');
                if ($oldIds->isNotEmpty()) {
                    StockMovement::whereIn('variant_id', $oldIds)->delete();
                    Stock::whereIn('variant_id', $oldIds)->delete();
                    ProductVariant::withTrashed()->where('product_id', $product->id)->forceDelete();
                }

                foreach ($this->variants as $vd) {
                    if (!($vd['selected'] ?? true)) continue;

                    $variant = ProductVariant::create([
                        'product_id' => $product->id,
                        'name'       => $vd['name'],
                        'sku'        => $vd['sku'] ?: null,
                        'price'      => (float) ($vd['price'] ?: 0),
                        'cost'       => (float) ($vd['cost'] ?: 0),
                        'attributes' => $vd['attributes'],
                    ]);

                    if ($this->warehouseId) {
                        Stock::create([
                            'product_id'    => $product->id,
                            'variant_id'    => $variant->id,
                            'warehouse_id'  => $this->warehouseId,
                            'quantity'      => (int) ($vd['qty'] ?? 0),
                            'reorder_level' => 10,
                        ]);
                    }
                }
            }
        });
    }

    // ────────────────────────────────────────────────────────
    // Render
    // ────────────────────────────────────────────────────────

    public function render()
    {
        $maxSteps   = $this->productType === 'variable' ? 6 : 5;
        $stepLabels = $this->productType === 'variable'
            ? ['Type', 'Basic Info', 'Attributes', 'Variants', 'Pricing & Stock', 'Review']
            : ['Type', 'Basic Info', 'Pricing', 'Stock', 'Review'];

        $price  = (float) $this->price;
        $cost   = (float) $this->cost;
        $profit = $price > 0 ? $price - $cost : 0.0;
        $profitMargin = $price > 0 ? round(($price - $cost) / $price * 100, 1) : 0.0;

        $selectedVariants = array_filter($this->variants, fn ($v) => $v['selected'] ?? true);
        $totalSelectedVariants = count($selectedVariants);
        $totalVariantStock = (int) array_sum(array_map(fn ($v) => (int) ($v['qty'] ?? 0), $selectedVariants));

        $variantPrices = array_map(fn ($v) => (float) $v['price'], array_filter($selectedVariants, fn ($v) => ($v['price'] ?? 0) > 0));
        $priceRange = empty($variantPrices)
            ? ['min' => 0.0, 'max' => 0.0]
            : ['min' => min($variantPrices), 'max' => max($variantPrices)];

        return view('livewire.products.product-creation-wizard', [
            'maxSteps'              => $maxSteps,
            'stepLabels'            => $stepLabels,
            'profit'                => $profit,
            'profitMargin'          => $profitMargin,
            'totalSelectedVariants' => $totalSelectedVariants,
            'totalVariantStock'     => $totalVariantStock,
            'priceRange'            => $priceRange,
            'warehouses'            => Auth::user()->warehouses()->where('is_active', true)->get(),
            'connections'           => $this->store->connections()->where('status', 'active')->get(),
            'currency'              => $this->store->currency ?? 'MAD',
        ]);
    }
}
