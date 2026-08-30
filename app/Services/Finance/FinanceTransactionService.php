<?php

declare(strict_types=1);

namespace App\Services\Finance;

use App\Enums\FinanceTransactionDirection;
use App\Enums\FinanceTransactionType;
use App\Models\FinanceTransaction;
use Carbon\CarbonInterface;
use Illuminate\Database\QueryException;

/**
 * The single write path for the finance ledger. Append-only: nothing here
 * ever updates or deletes an existing row — a correction is always a new
 * transaction (see FinanceOrderTransactionService/FinanceExpenseService).
 *
 * Idempotency: a (source_type, source_id, type, sequence) quadruple may
 * only ever produce ONE transaction (enforced by a DB unique index AND
 * checked here first, so callers get the existing row back instead of a
 * duplicate-key exception). `sequence` defaults to 0 and is only ever
 * something else for genuinely repeatable cycles (e.g. an expense being
 * paid, reversed, and paid again — see FinanceExpenseService) — for every
 * other, one-shot transaction type it's always 0, so this behaves exactly
 * like the old (source_type, source_id, type) uniqueness. Manual
 * adjustments (no source) are never source-constrained.
 */
class FinanceTransactionService
{
    /**
     * @param  array{organization_id: string, store_id?: ?string, account_id?: ?string, direction: FinanceTransactionDirection|string, type: FinanceTransactionType|string, sequence?: int, amount: float|string, currency?: string, occurred_at: CarbonInterface|string, source_type?: ?string, source_id?: ?string, reference?: ?string, description?: ?string, created_by?: ?string, metadata?: ?array}  $attributes
     */
    public function record(array $attributes): FinanceTransaction
    {
        $sourceType = $attributes['source_type'] ?? null;
        $sourceId = $attributes['source_id'] ?? null;
        $type = $attributes['type'] instanceof FinanceTransactionType ? $attributes['type']->value : $attributes['type'];
        $sequence = $attributes['sequence'] ?? 0;

        if ($sourceType !== null && $sourceId !== null) {
            $existing = $this->findExisting($sourceType, $sourceId, $type, $sequence);

            if ($existing !== null) {
                return $existing;
            }
        }

        try {
            return FinanceTransaction::query()->create([
                'organization_id' => $attributes['organization_id'],
                'store_id' => $attributes['store_id'] ?? null,
                'account_id' => $attributes['account_id'] ?? null,
                'direction' => $attributes['direction'],
                'type' => $type,
                'sequence' => $sequence,
                'amount' => $attributes['amount'],
                'currency' => $attributes['currency'] ?? 'MAD',
                'occurred_at' => $attributes['occurred_at'],
                'source_type' => $sourceType,
                'source_id' => $sourceId,
                'reference' => $attributes['reference'] ?? null,
                'description' => $attributes['description'] ?? null,
                'created_by' => $attributes['created_by'] ?? null,
                'metadata' => $attributes['metadata'] ?? null,
            ]);
        } catch (QueryException $e) {
            // Race: two concurrent requests both passed the check above. The
            // DB's unique index is the real guarantee — fall back to it.
            if ($sourceType !== null && $sourceId !== null && $this->isDuplicateKeyError($e)) {
                $existing = $this->findExisting($sourceType, $sourceId, $type, $sequence);

                if ($existing !== null) {
                    return $existing;
                }
            }

            throw $e;
        }
    }

    /** True when a transaction already exists for this (source, type, sequence) — the exact idempotency check `record()` uses. */
    public function alreadyRecorded(string $sourceType, string $sourceId, FinanceTransactionType|string $type, int $sequence = 0): bool
    {
        return $this->findExisting($sourceType, $sourceId, $type instanceof FinanceTransactionType ? $type->value : $type, $sequence) !== null;
    }

    private function findExisting(string $sourceType, string $sourceId, string $type, int $sequence = 0): ?FinanceTransaction
    {
        return FinanceTransaction::withoutOrganizationTenancy(fn () => FinanceTransaction::query()
            ->where('source_type', $sourceType)
            ->where('source_id', $sourceId)
            ->where('type', $type)
            ->where('sequence', $sequence)
            ->first());
    }

    private function isDuplicateKeyError(QueryException $e): bool
    {
        return (int) $e->getCode() === 23000
            || str_contains($e->getMessage(), 'finance_tx_source_type_unique')
            || str_contains($e->getMessage(), 'finance_tx_source_type_sequence_unique');
    }
}
