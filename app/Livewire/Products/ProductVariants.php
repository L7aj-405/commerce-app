<?php

declare(strict_types=1);

namespace App\Livewire\Products;

use App\Enums\StockMovementType;
use App\Models\Product;
use App\Models\ProductAttribute;
use App\Models\ProductAttributeValue;
use App\Models\ProductVariant;
use App\Models\Stock;
use App\Models\StockMovement;
use App\Services\Sync\VariantPushService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Livewire\Component;

class ProductVariants extends Component
{
    public Product $product;

    // ── Variant modal ─────────────────────────────────────────────
    public bool $showModal = false;
    public ?string $editingVariantId = null;

    public string $variantName = '';
    public bool $variantNameLocked = false;
    public string $variantSku = '';
    public float $variantPrice = 0.0;
    public float $variantCost = 0.0;
    public float $variantComparePrice = 0.0;
    public string $variantInitialQty = '0';

    /** Selected ProductAttributeValue IDs */
    public array $selectedValueIds = [];

    // ── Attribute manager (inline panel) ─────────────────────────
    public bool $showAttributeManager = false;
    public string $newAttrName = '';

    /** [attribute_id => draft_value_string] for per-attribute "add value" inputs */
    public array $newAttrValueInputs = [];

    // ─────────────────────────────────────────────────────────────

    public function mount(Product $product): void
    {
        $this->product = $product;
    }

    // ── Variant modal ─────────────────────────────────────────────

    public function openAddModal(): void
    {
        $this->resetForm();
        $this->showModal = true;
    }

    public function openEditModal(string $variantId): void
    {
        $variant = ProductVariant::where('product_id', $this->product->id)
            ->findOrFail($variantId);

        $this->resetForm();
        $this->editingVariantId    = $variantId;
        $this->variantName         = $variant->name ?? '';
        $this->variantNameLocked   = $this->variantName !== '';
        $this->variantSku          = $variant->sku ?? '';
        $this->variantPrice        = (float) ($variant->price ?? 0);
        $this->variantCost         = (float) ($variant->cost ?? 0);
        $this->variantComparePrice = (float) ($variant->compare_price ?? 0);

        // Load existing attribute value selections
        $this->selectedValueIds = $variant->attributeValues()->pluck('product_attribute_values.id')->toArray();

        $this->showModal = true;
    }

    public function closeModal(): void
    {
        $this->showModal = false;
        $this->resetForm();
    }

    /** Rebuild auto-fill name when checkbox selection changes */
    public function updatedSelectedValueIds(): void
    {
        if ($this->variantNameLocked) {
            return;
        }

        if (empty($this->selectedValueIds)) {
            $this->variantName = '';
            return;
        }

        $this->variantName = ProductAttributeValue::whereIn('id', $this->selectedValueIds)
            ->with('attribute')
            ->get()
            ->groupBy(fn ($v) => $v->attribute?->name ?? '')
            ->map(fn ($vals) => $vals->pluck('value')->join(', '))
            ->values()
            ->join(' / ');
    }

    public function updatedVariantName(string $value): void
    {
        $this->variantNameLocked = $value !== '';
    }

    public function saveVariant(VariantPushService $pushService): void
    {
        $this->validate([
            'variantSku'          => 'required|string|max:255',
            'variantPrice'        => 'required|numeric|min:0',
            'variantCost'         => 'numeric|min:0',
            'variantComparePrice' => 'numeric|min:0',
        ]);

        $name = trim($this->variantName);

        if ($name === '') {
            $name = $this->variantSku;
        }

        $data = [
            'product_id'    => $this->product->id,
            'name'          => $name,
            'sku'           => $this->variantSku,
            'price'         => $this->variantPrice,
            'cost'          => $this->variantCost,
            'compare_price' => $this->variantComparePrice,
        ];

        if ($this->editingVariantId !== null) {
            $variant = ProductVariant::where('product_id', $this->product->id)
                ->findOrFail($this->editingVariantId);

            $variant->update($data);
            $variant->syncAttributeValues($this->selectedValueIds);

            if ($variant->canPushToPlatform()) {
                $results = $pushService->pushVariant($variant);
                $synced  = collect($results)->where('success', true)->pluck('platform')->map(fn ($p) => ucfirst((string) ($p ?? '')));
                $failed  = collect($results)->where('success', false)->pluck('platform')->map(fn ($p) => ucfirst((string) ($p ?? '')));

                $message = $synced->isNotEmpty()
                    ? "Variant updated and synced to: {$synced->join(', ')}"
                    : ($failed->isNotEmpty() ? 'Variant updated (platform sync failed)' : 'Variant updated successfully');

                $this->dispatch('notify', message: $message, type: $failed->isEmpty() ? 'success' : 'warning');
            } elseif (!empty($this->product->external_id)) {
                $results = $pushService->createVariant($variant);
                $synced  = collect($results)->where('success', true)->pluck('platform')->map(fn ($p) => ucfirst((string) ($p ?? '')));
                $failed  = collect($results)->where('success', false)->pluck('platform')->map(fn ($p) => ucfirst((string) ($p ?? '')));

                $message = $synced->isNotEmpty()
                    ? "Variant updated and pushed to: {$synced->join(', ')}"
                    : ($failed->isNotEmpty() ? 'Variant updated (platform push failed)' : 'Variant updated successfully');

                $this->dispatch('notify', message: $message, type: $failed->isEmpty() ? 'success' : 'warning');
            } else {
                $this->dispatch('notify', message: 'Variant updated successfully', type: 'success');
            }
        } else {
            $variant = ProductVariant::create($data);
            $variant->syncAttributeValues($this->selectedValueIds);

            $this->createVariantStock($variant, max(0, (int) $this->variantInitialQty));

            $results = $pushService->createVariant($variant);
            $synced  = collect($results)->where('success', true)->pluck('platform')->map(fn ($p) => ucfirst((string) ($p ?? '')));
            $failed  = collect($results)->where('success', false)->pluck('platform')->map(fn ($p) => ucfirst((string) ($p ?? '')));

            $label   = "'{$variant->getDisplayName()}'";
            $message = $synced->isNotEmpty()
                ? "Variant {$label} created and synced to: {$synced->join(', ')}"
                : ($failed->isNotEmpty()
                    ? "Variant {$label} created (platform sync failed)"
                    : "Variant {$label} added successfully");

            $this->dispatch('notify', message: $message, type: $failed->isEmpty() ? 'success' : 'warning');
        }

        $this->closeModal();
    }

    public function deleteVariant(string $variantId, VariantPushService $pushService): void
    {
        $variant = ProductVariant::where('product_id', $this->product->id)
            ->findOrFail($variantId);

        $syncedPlatforms = [];
        $name            = $variant->getDisplayName();

        if ($variant->canPushToPlatform()) {
            $results         = $pushService->deleteVariant($variant);
            $syncedPlatforms = collect($results)
                ->where('success', true)
                ->pluck('platform')
                ->map(fn ($p) => ucfirst((string) ($p ?? '')))
                ->all();
        }

        DB::transaction(function () use ($variant): void {
            StockMovement::where('variant_id', $variant->id)->delete();
            Stock::where('variant_id', $variant->id)->delete();
            $variant->attributeValues()->detach();
            $variant->delete();
        });

        $message = !empty($syncedPlatforms)
            ? "Variant '{$name}' deleted from SaaS and: " . implode(', ', $syncedPlatforms)
            : "Variant '{$name}' deleted";

        $this->dispatch('notify', message: $message, type: 'success');
    }

    // ── Attribute manager ─────────────────────────────────────────

    public function addAttribute(): void
    {
        $name = trim($this->newAttrName);

        $this->validate(['newAttrName' => 'required|string|max:100']);

        try {
            ProductAttribute::findOrCreateForProduct($this->product->id, $name);
            $this->newAttrName = '';
            $this->dispatch('notify', message: "Attribute '{$name}' added", type: 'success');
        } catch (\Throwable $e) {
            $this->dispatch('notify', message: 'Error: ' . $e->getMessage(), type: 'error');
        }
    }

    public function addAttributeValue(string $attributeId): void
    {
        $value = trim($this->newAttrValueInputs[$attributeId] ?? '');

        if ($value === '') {
            return;
        }

        $attr = ProductAttribute::where('product_id', $this->product->id)->find($attributeId);

        if (!$attr) {
            return;
        }

        try {
            ProductAttributeValue::findOrCreateForAttribute($attributeId, $value);
            $this->newAttrValueInputs[$attributeId] = '';
            $this->dispatch('notify', message: "'{$value}' added to {$attr->name}", type: 'success');
        } catch (\Throwable $e) {
            $this->dispatch('notify', message: 'Error: ' . $e->getMessage(), type: 'error');
        }
    }

    public function deleteAttributeValue(string $valueId): void
    {
        $val = ProductAttributeValue::with('attribute')->find($valueId);

        if ($val && $val->attribute?->product_id === $this->product->id) {
            $name = $val->value;
            $val->delete();
            $this->dispatch('notify', message: "Value '{$name}' deleted", type: 'success');
        }
    }

    public function deleteAttribute(string $attributeId): void
    {
        $attr = ProductAttribute::where('product_id', $this->product->id)->find($attributeId);

        if ($attr) {
            $name = $attr->name;
            $attr->delete();
            $this->dispatch('notify', message: "Attribute '{$name}' deleted", type: 'success');
        }
    }

    // ── Private helpers ───────────────────────────────────────────

    private function createVariantStock(ProductVariant $variant, int $initialQty = 0): void
    {
        $warehouse = $this->product->store->getPrimaryWarehouse();

        if (!$warehouse) {
            Log::warning('No primary warehouse — skipping variant stock creation', ['variant_id' => $variant->id]);
            return;
        }

        try {
            Stock::updateOrCreate(
                [
                    'product_id'   => $variant->product_id,
                    'variant_id'   => $variant->id,
                    'warehouse_id' => $warehouse->id,
                ],
                ['quantity' => $initialQty]
            );

            StockMovement::create([
                'warehouse_id' => $warehouse->id,
                'product_id'   => $variant->product_id,
                'variant_id'   => $variant->id,
                'user_id'      => $warehouse->user_id,
                'type'         => StockMovementType::In->value,
                'quantity'     => $initialQty,
                'notes'        => "Initial stock for new variant: {$variant->getDisplayName()}",
            ]);
        } catch (\Throwable $e) {
            Log::warning('Failed to create variant stock', ['variant_id' => $variant->id, 'error' => $e->getMessage()]);
        }
    }

    private function resetForm(): void
    {
        $this->editingVariantId    = null;
        $this->variantName         = '';
        $this->variantNameLocked   = false;
        $this->variantSku          = '';
        $this->variantPrice        = 0.0;
        $this->variantCost         = 0.0;
        $this->variantComparePrice = 0.0;
        $this->variantInitialQty   = '0';
        $this->selectedValueIds    = [];
        $this->resetValidation();
    }

    public function render()
    {
        return view('livewire.products.product-variants', [
            'variants'          => $this->product->variants()->with(['stocks', 'attributeValues.attribute'])->get(),
            'productAttributes' => $this->product->attributes()->with('values')->get(),
        ]);
    }
}
