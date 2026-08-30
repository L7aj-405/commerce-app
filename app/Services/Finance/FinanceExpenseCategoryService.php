<?php

declare(strict_types=1);

namespace App\Services\Finance;

use App\Models\FinanceExpenseCategory;
use App\Models\Organization;
use Illuminate\Support\Str;

/**
 * Tenant-scoped expense category management. Default categories are seeded
 * once per organization (e.g. right after onboarding) and are never
 * hard-deleted while in use — see FinanceExpenseCategoryPolicy::delete().
 */
class FinanceExpenseCategoryService
{
    /** @return array<int, string> */
    public static function defaultCategoryNames(): array
    {
        return [
            'Product purchases',
            'Delivery / Transport',
            'Packaging',
            'Salaries',
            'Freelancers',
            'Ads / Marketing',
            'Software / Apps',
            'Domain / Hosting',
            'Rent',
            'Internet / Phone',
            'Bank fees',
            'Refunds',
            'Office supplies',
            'Other',
        ];
    }

    /** Seeds the default categories the first time an organization touches Finance. */
    public function ensureSeeded(Organization $organization): void
    {
        $hasAny = FinanceExpenseCategory::withoutOrganizationTenancy(
            fn () => FinanceExpenseCategory::query()->where('organization_id', $organization->id)->exists(),
        );

        if (! $hasAny) {
            $this->seedDefaults($organization);
        }
    }

    /** Idempotent — safe to call multiple times for the same organization. */
    public function seedDefaults(Organization $organization): void
    {
        foreach (self::defaultCategoryNames() as $name) {
            FinanceExpenseCategory::withoutOrganizationTenancy(fn () => FinanceExpenseCategory::query()->updateOrCreate(
                ['organization_id' => $organization->id, 'name' => $name],
                ['slug' => $this->uniqueSlug($organization->id, $name), 'is_system' => true, 'is_active' => true],
            ));
        }
    }

    public function create(Organization $organization, array $data): FinanceExpenseCategory
    {
        return FinanceExpenseCategory::query()->create([
            'organization_id' => $organization->id,
            'name' => $data['name'],
            'slug' => $this->uniqueSlug($organization->id, $data['name']),
            'group' => $data['group'] ?? null,
            'color' => $data['color'] ?? null,
            'icon' => $data['icon'] ?? null,
            'is_system' => false,
            'is_active' => $data['is_active'] ?? true,
        ]);
    }

    public function update(FinanceExpenseCategory $category, array $data): FinanceExpenseCategory
    {
        $attributes = [
            'group' => $data['group'] ?? null,
            'color' => $data['color'] ?? null,
            'icon' => $data['icon'] ?? null,
        ];

        if (array_key_exists('is_active', $data)) {
            $attributes['is_active'] = (bool) $data['is_active'];
        }

        if ($data['name'] !== $category->name) {
            $attributes['name'] = $data['name'];
            $attributes['slug'] = $this->uniqueSlug($category->organization_id, $data['name'], $category->id);
        }

        $category->update($attributes);

        return $category->refresh();
    }

    /** System categories, or categories already used by an expense, are deactivated instead of deleted. */
    public function deactivate(FinanceExpenseCategory $category): FinanceExpenseCategory
    {
        $category->update(['is_active' => false]);

        return $category->refresh();
    }

    public function delete(FinanceExpenseCategory $category): void
    {
        $category->delete();
    }

    private function uniqueSlug(string $organizationId, string $name, ?string $ignoreId = null): string
    {
        $base = Str::slug($name) ?: 'category';
        $slug = $base;
        $i = 1;

        while (
            FinanceExpenseCategory::withoutOrganizationTenancy(fn () => FinanceExpenseCategory::query()
                ->where('organization_id', $organizationId)
                ->where('slug', $slug)
                ->when($ignoreId, fn ($q) => $q->whereKeyNot($ignoreId))
                ->exists())
        ) {
            $slug = "{$base}-{$i}";
            $i++;
        }

        return $slug;
    }
}
