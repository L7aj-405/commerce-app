<?php

declare(strict_types=1);

namespace App\Livewire\Products;

use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\Stock;
use App\Models\Store;
use App\Services\Sync\ProductPushService;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Layout;
use Livewire\Component;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

#[Layout('layouts.app')]
class ProductEditWizard extends Component
{
    public Store $store;
    public Product $product;

    // ── Wizard ──────────────────────────────────────────────
    public int    $step      = 1;
    public string $productId = '';

    // ── Step 1: Type ────────────────────────────────────────
    public string  $productType           = 'simple';
    public bool    $showTypeChangeConfirm = false;
    public ?string $pendingTypeChange     = null;

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

    // ── Variable: Attributes & Variants ─────────────────────
    public array  $productAttributes  = [];
    public string $newAttrName        = '';
    public array  $newAttrValueInputs = [];
    public array  $variants           = [];
    public string $baseQty            = '0';

    // ── Save state ──────────────────────────────────────────
    public string  $saveStatus  = 'saved';
    public ?string $lastSavedAt = null;

    // ── Push modal ──────────────────────────────────────────
    public bool  $showPushModal         = false;
    public array $selectedConnectionIds = [];
    public array $pushResults           = [];
    public bool  $isPushing             = false;
    public bool  $pushDone              = false;

    // ────────────────────────────────────────────────────────
    // Mount
    // ────────────────────────────────────────────────────────

    public function mount(Store $store, Product $product): void
    {
        $this->store     = $store;
        $this->product   = $product;
        $this->productId = $product->id;

        $this->productType  = $product->type;
        $this->name         = $product->name;
        $this->sku          = $product->sku ?? '';
        $this->description  = $product->description ?? '';
        $this->price        = $product->price ? (string) $product->price : '';
        $this->cost         = $product->cost ? (string) $product->cost : '';
        $this->comparePrice = $product->compare_price ? (string) $product->compare_price : '';

        $warehouse = $store->getPrimaryWarehouse()
            ?? Auth::user()->warehouses()->where('is_active', true)->first();

        if ($warehouse) {
            $this->warehouseId = $warehouse->id;
        }

        if ($product->isSimple()) {
            $stock = $product->stocks()
                ->whereNull('variant_id')
                ->when($this->warehouseId, fn ($q) => $q->where('warehouse_id', $this->warehouseId))
                ->first();
            $this->quantity = $stock ? (int) $stock->quantity : 0;
        } else {
            $product->load('variants.stocks');
            $this->productAttributes = $this->buildAttributesFromProduct();

            foreach ($product->variants as $variant) {
                $attrs = $variant->getAttribute('attributes') ?? [];
                $qty   = $this->warehouseId
                    ? (int) $variant->stocks->where('warehouse_id', $this->warehouseId)->sum('quantity')
                    : (int) $variant->stocks->sum('quantity');

                $this->variants[] = [
                    'id'         => $variant->id,
                    'combo_key'  => implode('|', array_values($attrs)),
                    'name'       => $variant->name,
                    'attributes' => $attrs,
                    'sku'        => $variant->sku ?? '',
                    'price'      => $variant->price ? (string) $variant->price : '',
                    'cost'       => $variant->cost ? (string) $variant->cost : '',
                    'qty'        => $qty,
                    'selected'   => true,
                ];
            }
        }
    }

    protected function buildAttributesFromProduct(): array
    {
        $attrs = [];
        foreach ($this->product->variants as $variant) {
            foreach ($variant->getAttribute('attributes') ?? [] as $name => $value) {
                if (!isset($attrs[$name])) {
                    $attrs[$name] = ['name' => $name, 'values' => []];
                }
                if ($value !== null && $value !== '' && !in_array((string) $value, $attrs[$name]['values'], true)) {
                    $attrs[$name]['values'][] = (string) $value;
                }
            }
        }
        return array_values($attrs);
    }

    // ────────────────────────────────────────────────────────
    // Type change
    // ────────────────────────────────────────────────────────

    public function changeProductType(string $newType): void
    {
        if ($newType === $this->productType) return;
        $this->pendingTypeChange     = $newType;
        $this->showTypeChangeConfirm = true;
    }

    public function cancelTypeChange(): void
    {
        $this->pendingTypeChange     = null;
        $this->showTypeChangeConfirm = false;
    }

    public function confirmTypeChange(): void
    {
        $newType = $this->pendingTypeChange;
        $oldType = $this->productType;

        if ($newType === null || $newType === $oldType) {
            $this->cancelTypeChange();
            return;
        }

        if ($oldType === 'variable' && $newType === 'simple') {
            $this->deleteVariantsForConversion();
            $this->productAttributes  = [];
            $this->variants           = [];
            $this->quantity           = 0;
        } else {
            // simple → variable: prepare for fresh attribute setup
            $this->productAttributes  = [];
            $this->variants           = [];
            $this->newAttrName        = '';
            $this->newAttrValueInputs = [];
        }

        $this->productType = $newType;

        Product::where('id', $this->productId)->update(['type' => $newType]);

        $this->cancelTypeChange();
    }

    private function deleteVariantsForConversion(): void
    {
        DB::transaction(function (): void {
            Stock::whereIn('variant_id', $this->product->variants()->pluck('id'))->delete();
            $this->product->variants()->delete();
            $this->product->attributes()->delete();
        });

        $this->product = $this->product->fresh();
    }

    // ────────────────────────────────────────────────────────
    // Navigation
    // ────────────────────────────────────────────────────────

    protected function computeMaxSteps(): int
    {
        if ($this->productType === 'simple') return 5;
        // Variable with no variants = just converted or new → needs attribute setup (6 steps like creation wizard)
        return empty($this->variants) ? 6 : 5;
    }

    public function nextStep(): void
    {
        $this->resetErrorBag();
        if (!$this->validateCurrentStep()) return;
        if (!empty(trim($this->name))) $this->autosave();
        $this->step = min($this->step + 1, $this->computeMaxSteps());
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

    protected function isNewVariable(): bool
    {
        return $this->productType === 'variable' && empty($this->variants);
    }

    protected function validateCurrentStep(): bool
    {
        try {
            match (true) {
                $this->step === 2 => $this->validate([
                    'name' => 'required|string|max:255',
                ], [
                    'name.required' => 'Product name is required.',
                ]),

                $this->step === 3 && $this->productType === 'simple' => $this->validate([
                    'price' => 'required|numeric|min:0',
                    'cost'  => 'required|numeric|min:0',
                ], [
                    'price.required' => 'Sale price is required.',
                    'cost.required'  => 'Cost price is required.',
                ]),

                // Attributes step (new variable product)
                $this->step === 3 && $this->isNewVariable() => $this->validateAttributes(),

                // Variants step (new variable product)
                $this->step === 4 && $this->isNewVariable() => $this->validateVariants(),

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
    // Auto-save (always UPDATE — product already exists)
    // ────────────────────────────────────────────────────────

    public function autosave(): void
    {
        if (empty(trim($this->name))) return;

        $this->saveStatus = 'saving';

        try {
            Product::where('id', $this->productId)->update([
                'name'          => $this->name,
                'description'   => $this->description,
                'price'         => (float) ($this->price ?: 0),
                'cost'          => (float) ($this->cost ?: 0),
                'compare_price' => $this->comparePrice ? (float) $this->comparePrice : null,
            ]);
            $this->saveStatus  = 'saved';
            $this->lastSavedAt = now()->format('H:i:s');
        } catch (\Throwable) {
            $this->saveStatus = 'error';
        }
    }

    public function updatedName(): void         { $this->autosave(); }
    public function updatedDescription(): void  { $this->autosave(); }
    public function updatedPrice(): void        { $this->autosave(); }
    public function updatedCost(): void         { $this->autosave(); }
    public function updatedComparePrice(): void { $this->autosave(); }

    // ────────────────────────────────────────────────────────
    // Attributes (variable — new-variable path only)
    // ────────────────────────────────────────────────────────

    public function addAttribute(): void
    {
        $name = trim($this->newAttrName);
        if (empty($name)) return;

        foreach ($this->productAttributes as $a) {
            if (strtolower($a['name']) === strtolower($name)) return;
        }

        $this->productAttributes[] = ['name' => $name, 'values' => []];
        $this->newAttrName = '';
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
    // Variant generation (new-variable path)
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
                'id'         => $prev['id']       ?? null,
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

    // ────────────────────────────────────────────────────────
    // Bulk variant controls
    // ────────────────────────────────────────────────────────

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

    public function saveChanges(): void
    {
        $currentStatus = Product::find($this->productId)?->status ?? 'draft';
        $this->finalize($currentStatus);
        session()->flash('success', 'Product changes saved.');
        $this->redirect(route('stores.products.index', $this->store));
    }

    public function openPushModal(): void
    {
        $currentStatus = Product::find($this->productId)?->status ?? 'draft';
        $this->finalize($currentStatus);

        $connections = $this->store->connections()->where('status', 'active')->get();
        $this->selectedConnectionIds = $connections->pluck('id')->toArray();
        $this->pushResults   = [];
        $this->pushDone      = false;
        $this->isPushing     = false;
        $this->showPushModal = true;
    }

    public function closePushModal(): void
    {
        $this->showPushModal = false;
        $this->pushDone      = false;
        $this->pushResults   = [];
    }

    public function confirmPush(): void
    {
        $this->isPushing   = true;
        $this->pushResults = [];

        try {
            $this->finalize('active');

            $product = Product::with(['variants', 'stocks', 'store'])->findOrFail($this->productId);

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
                    'message'  => 'No variants found. Cannot push a variable product without variants.',
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

            foreach ($connections->groupBy('platform') as $platform => $platformConnections) {
                $results = $product->external_id
                    ? $service->pushProduct($product, $platform)
                    : $service->createProduct($product, $platform);

                $product->refresh();

                foreach ($results as $r) {
                    $this->pushResults[] = array_merge($r, ['platform' => $platform]);
                }
            }

            $succeeded = collect($this->pushResults)->where('success', true)->count();
            $total     = count($this->pushResults);

            if ($succeeded > 0) {
                session()->flash('success', $succeeded === $total
                    ? "Product pushed on {$total} " . Str::plural('platform', $total) . '.'
                    : "Product pushed on {$succeeded}/{$total} " . Str::plural('platform', $total) . '.'
                );
                $this->redirect(route('stores.products.index', $this->store));
                return;
            }

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
            Product::where('id', $this->productId)->update([
                'name'          => $this->name,
                'sku'           => $this->sku ?: null,
                'type'          => $this->productType,
                'description'   => $this->description,
                'price'         => (float) ($this->price ?: 0),
                'cost'          => (float) ($this->cost ?: 0),
                'compare_price' => $this->comparePrice ? (float) $this->comparePrice : null,
                'status'        => $status,
            ]);

            $product = Product::findOrFail($this->productId);

            if ($this->productType === 'simple') {
                if ($this->warehouseId) {
                    Stock::updateOrCreate(
                        ['product_id' => $product->id, 'warehouse_id' => $this->warehouseId, 'variant_id' => null],
                        ['quantity' => $this->quantity, 'reorder_level' => 10]
                    );
                }
            } else {
                foreach ($this->variants as $vd) {
                    if (!($vd['selected'] ?? true)) continue;

                    if (!empty($vd['id'])) {
                        // Update existing variant in-place (preserves external_id)
                        ProductVariant::where('id', $vd['id'])->update([
                            'price' => (float) ($vd['price'] ?: 0),
                            'cost'  => (float) ($vd['cost'] ?: 0),
                        ]);
                        $variantId = $vd['id'];
                    } else {
                        // Create new variant (converted from simple, or freshly added)
                        $variant = ProductVariant::create([
                            'product_id' => $product->id,
                            'name'       => $vd['name'],
                            'sku'        => $vd['sku'] ?: null,
                            'price'      => (float) ($vd['price'] ?: 0),
                            'cost'       => (float) ($vd['cost'] ?: 0),
                            'attributes' => $vd['attributes'],
                        ]);
                        $variantId = $variant->id;
                    }

                    if ($this->warehouseId) {
                        Stock::updateOrCreate(
                            ['product_id' => $product->id, 'variant_id' => $variantId, 'warehouse_id' => $this->warehouseId],
                            ['quantity' => (int) ($vd['qty'] ?? 0), 'reorder_level' => 10]
                        );
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
        $isNewVariable = $this->isNewVariable();
        $maxSteps      = $this->computeMaxSteps();

        $stepLabels = match (true) {
            $this->productType === 'simple' => ['Type', 'Basic Info', 'Pricing', 'Stock', 'Review'],
            $isNewVariable                  => ['Type', 'Basic Info', 'Attributes', 'Variants', 'Pricing & Stock', 'Review'],
            default                         => ['Type', 'Basic Info', 'Variants', 'Pricing & Stock', 'Review'],
        };

        $price        = (float) $this->price;
        $cost         = (float) $this->cost;
        $profit       = $price > 0 ? $price - $cost : 0.0;
        $profitMargin = $price > 0 ? round(($price - $cost) / $price * 100, 1) : 0.0;

        $selectedVariants      = array_filter($this->variants, fn ($v) => $v['selected'] ?? true);
        $totalSelectedVariants = count($selectedVariants);
        $totalVariantStock     = (int) array_sum(array_map(fn ($v) => (int) ($v['qty'] ?? 0), $selectedVariants));

        $variantPrices = array_map(
            fn ($v) => (float) $v['price'],
            array_filter($selectedVariants, fn ($v) => ((float) ($v['price'] ?? 0)) > 0)
        );
        $priceRange = empty($variantPrices)
            ? ['min' => 0.0, 'max' => 0.0]
            : ['min' => min($variantPrices), 'max' => max($variantPrices)];

        // Data for the type-change confirmation modal
        $pendingVariantCount = count($this->variants);
        $pendingVariantStock = (int) array_sum(array_map(fn ($v) => (int) ($v['qty'] ?? 0), $this->variants));

        return view('livewire.products.product-edit-wizard', [
            'maxSteps'              => $maxSteps,
            'stepLabels'            => $stepLabels,
            'isNewVariable'         => $isNewVariable,
            'profit'                => $profit,
            'profitMargin'          => $profitMargin,
            'totalSelectedVariants' => $totalSelectedVariants,
            'totalVariantStock'     => $totalVariantStock,
            'priceRange'            => $priceRange,
            'pendingVariantCount'   => $pendingVariantCount,
            'pendingVariantStock'   => $pendingVariantStock,
            'warehouses'            => Auth::user()->warehouses()->where('is_active', true)->get(),
            'connections'           => $this->store->connections()->where('status', 'active')->get(),
            'currency'              => $this->store->currency ?? 'MAD',
        ]);
    }
}
