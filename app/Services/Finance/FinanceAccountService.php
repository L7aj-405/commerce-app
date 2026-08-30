<?php

declare(strict_types=1);

namespace App\Services\Finance;

use App\Enums\FinanceAccountType;
use App\Models\FinanceAccount;
use App\Models\Organization;

class FinanceAccountService
{
    /** @return array<int, array{name: string, type: FinanceAccountType, is_default: bool}> */
    public static function defaultAccounts(): array
    {
        return [
            ['name' => 'Cash', 'type' => FinanceAccountType::Cash, 'is_default' => true],
            ['name' => 'Bank', 'type' => FinanceAccountType::Bank, 'is_default' => false],
            ['name' => 'Card / TPE', 'type' => FinanceAccountType::Card, 'is_default' => false],
            ['name' => 'COD Receivable', 'type' => FinanceAccountType::CodReceivable, 'is_default' => false],
            ['name' => 'Delivery Company Balance', 'type' => FinanceAccountType::DeliveryCompany, 'is_default' => false],
        ];
    }

    public function ensureSeeded(Organization $organization): void
    {
        $hasAny = FinanceAccount::withoutOrganizationTenancy(
            fn () => FinanceAccount::query()->where('organization_id', $organization->id)->exists(),
        );

        if ($hasAny) {
            return;
        }

        foreach (self::defaultAccounts() as $account) {
            FinanceAccount::withoutOrganizationTenancy(fn () => FinanceAccount::query()->updateOrCreate(
                ['organization_id' => $organization->id, 'name' => $account['name']],
                ['type' => $account['type'], 'is_default' => $account['is_default'], 'is_active' => true],
            ));
        }
    }

    public function create(Organization $organization, array $data): FinanceAccount
    {
        $account = FinanceAccount::query()->create([
            'organization_id' => $organization->id,
            'store_id' => $data['store_id'] ?? null,
            'name' => $data['name'],
            'type' => $data['type'],
            'currency' => $data['currency'] ?? 'MAD',
            'is_default' => $data['is_default'] ?? false,
            'is_active' => $data['is_active'] ?? true,
        ]);

        if ($account->is_default) {
            $this->makeDefault($account);
        }

        return $account;
    }

    public function update(FinanceAccount $account, array $data): FinanceAccount
    {
        $account->update([
            'store_id' => $data['store_id'] ?? null,
            'name' => $data['name'],
            'type' => $data['type'],
            'currency' => $data['currency'] ?? $account->currency,
            'is_active' => array_key_exists('is_active', $data) ? (bool) $data['is_active'] : $account->is_active,
        ]);

        if (! empty($data['is_default'])) {
            $this->makeDefault($account);
        }

        return $account->refresh();
    }

    /** Only one default account per organization at a time. */
    private function makeDefault(FinanceAccount $account): void
    {
        FinanceAccount::query()
            ->where('organization_id', $account->organization_id)
            ->whereKeyNot($account->id)
            ->update(['is_default' => false]);

        $account->update(['is_default' => true]);
    }

    public function deactivate(FinanceAccount $account): FinanceAccount
    {
        $account->update(['is_active' => false, 'is_default' => false]);

        return $account->refresh();
    }

    public function delete(FinanceAccount $account): void
    {
        $account->delete();
    }

    /**
     * Best-effort account for a given account type — used by the order/expense
     * ledger sync to resolve "which account did this cash move through"
     * without forcing the caller to know account IDs. Seeds defaults first if
     * the organization has never touched Finance.
     */
    public function resolveByType(Organization $organization, FinanceAccountType $type): ?FinanceAccount
    {
        $this->ensureSeeded($organization);

        return FinanceAccount::query()
            ->where('organization_id', $organization->id)
            ->where('type', $type)
            ->where('is_active', true)
            ->orderByDesc('is_default')
            ->first();
    }
}
