<?php

declare(strict_types=1);

namespace App\Services\Finance;

use App\Enums\FinanceAccountType;
use App\Enums\FinanceTransactionDirection;
use App\Enums\FinanceTransactionType;
use App\Models\FinanceAccount;
use App\Models\FinanceTransaction;
use App\Models\Order;
use App\Models\Organization;
use App\Models\PosOrder;
use App\Models\User;
use Carbon\CarbonInterface;
use Illuminate\Support\Collection;
use Illuminate\Validation\ValidationException;

/**
 * Bridges the existing Orders/POS lifecycle into the Finance ledger, without
 * requiring either lifecycle to know Finance exists. Every public method is
 * idempotent (safe to call more than once for the same order) and resolves
 * its own organization from the order/store — callers never need to look
 * one up themselves.
 */
class FinanceOrderTransactionService
{
    public function __construct(
        private readonly FinanceTransactionService $transactions,
        private readonly FinanceAccountService $accounts,
        private readonly FinanceCodCollectabilityService $collectability,
    ) {}

    /**
     * Online order: record the sale, then either the payment (already
     * collected online) or a pending COD receivable — never both.
     *
     * IMPORTANT date rule: occurred_at is the order's OWN created_at (the
     * real sale date), not "now" — an order synced/imported late must still
     * land in the month it was actually placed, per the Phase 2 spec's
     * "August sale / September collection" requirement.
     */
    public function syncOrderFinancials(Order $order): void
    {
        $organization = $order->store?->organization;

        if ($organization === null) {
            return; // legacy pre-organization store — Finance has nothing to attach to
        }

        $occurredAt = $order->created_at ?? now();

        $this->transactions->record([
            'organization_id' => $organization->id,
            'store_id' => $order->store_id,
            'direction' => FinanceTransactionDirection::Neutral,
            'type' => FinanceTransactionType::SaleCreated,
            'amount' => $order->total,
            'currency' => $order->currency,
            'occurred_at' => $occurredAt,
            'source_type' => Order::class,
            'source_id' => $order->id,
            'reference' => $order->order_number,
            'description' => "Sale recorded for order {$order->order_number}",
        ]);

        if ($this->isLikelyCod($order)) {
            $account = $this->accounts->resolveByType($organization, FinanceAccountType::CodReceivable);

            $this->transactions->record([
                'organization_id' => $organization->id,
                'store_id' => $order->store_id,
                'account_id' => $account?->id,
                'direction' => FinanceTransactionDirection::Neutral,
                'type' => FinanceTransactionType::CodReceivableCreated,
                'amount' => $order->total,
                'currency' => $order->currency,
                'occurred_at' => $occurredAt,
                'source_type' => Order::class,
                'source_id' => $order->id,
                'reference' => $order->order_number,
                'description' => "COD pending collection for order {$order->order_number}",
            ]);

            return;
        }

        $account = $this->accounts->resolveByType($organization, FinanceAccountType::Bank);

        $this->transactions->record([
            'organization_id' => $organization->id,
            'store_id' => $order->store_id,
            'account_id' => $account?->id,
            'direction' => FinanceTransactionDirection::In,
            'type' => FinanceTransactionType::PaymentCollected,
            'amount' => $order->total,
            'currency' => $order->currency,
            'occurred_at' => $occurredAt,
            'source_type' => Order::class,
            'source_id' => $order->id,
            'reference' => $order->order_number,
            'description' => "Payment collected online for order {$order->order_number}",
        ]);
    }

    /**
     * POS order: always paid at the point of sale, so sale + collection are
     * recorded together, immediately, using the order's own payment_method.
     */
    public function syncPosOrderFinancials(PosOrder $order): void
    {
        $organization = $order->store?->organization;

        if ($organization === null || $order->status === 'cancelled') {
            return;
        }

        $occurredAt = $order->created_at ?? now();

        $this->transactions->record([
            'organization_id' => $organization->id,
            'store_id' => $order->store_id,
            'direction' => FinanceTransactionDirection::Neutral,
            'type' => FinanceTransactionType::SaleCreated,
            'amount' => $order->total_amount,
            'currency' => $order->store?->currency ?? 'MAD',
            'occurred_at' => $occurredAt,
            'source_type' => PosOrder::class,
            'source_id' => $order->id,
            'reference' => $order->receipt_number,
            'description' => "POS sale recorded for receipt {$order->receipt_number}",
        ]);

        $account = $this->accounts->resolveByType($organization, $this->posAccountType($order->payment_method));
        $amount = (float) $order->amount_paid > 0 ? $order->amount_paid : $order->total_amount;

        $this->transactions->record([
            'organization_id' => $organization->id,
            'store_id' => $order->store_id,
            'account_id' => $account?->id,
            'direction' => FinanceTransactionDirection::In,
            'type' => FinanceTransactionType::PaymentCollected,
            'amount' => $amount,
            'currency' => $order->store?->currency ?? 'MAD',
            'occurred_at' => $occurredAt,
            'source_type' => PosOrder::class,
            'source_id' => $order->id,
            'reference' => $order->receipt_number,
            'description' => "POS payment collected ({$order->payment_method}) for receipt {$order->receipt_number}",
        ]);
    }

    /**
     * Called from the shared OrderWorkflowService::transition() whenever an
     * order/POS order reaches Cancelled. If cash was never actually
     * collected (still-pending COD, or never synced into Finance), there is
     * nothing to reverse — a cancelled order simply stops counting as
     * collected revenue in reporting queries. If cash HAD been collected, a
     * reversing transaction is recorded (once — idempotent per order).
     */
    public function handleOrderCancelled(Order|PosOrder $order): void
    {
        $this->reverseCollectedCash($order, FinanceTransactionType::RefundPaid, 'Order cancelled after payment was collected — reversing cash.');
    }

    /** Called when a return is finalized (OrderWorkflowService reaching ReturnCompleted). */
    public function handleOrderReturned(Order|PosOrder $order): void
    {
        $this->reverseCollectedCash($order, FinanceTransactionType::ReturnAdjustment, 'Order returned after payment was collected — reversing cash.');
    }

    private function reverseCollectedCash(Order|PosOrder $order, FinanceTransactionType $type, string $description): void
    {
        $organization = $order->store?->organization;

        if ($organization === null) {
            return;
        }

        $sourceType = $order instanceof Order ? Order::class : PosOrder::class;
        $collected = $this->collectedAmountFor($sourceType, $order->id);

        if ($collected <= 0.0) {
            return;
        }

        $this->transactions->record([
            'organization_id' => $organization->id,
            'store_id' => $order->store_id,
            'direction' => FinanceTransactionDirection::Out,
            'type' => $type,
            'amount' => $collected,
            'currency' => $order instanceof Order ? $order->currency : ($order->store?->currency ?? 'MAD'),
            'occurred_at' => now(),
            'source_type' => $sourceType,
            'source_id' => $order->id,
            'reference' => $order instanceof Order ? $order->order_number : $order->receipt_number,
            'description' => $description,
        ]);
    }

    private function collectedAmountFor(string $sourceType, string $sourceId): float
    {
        return (float) FinanceTransaction::withoutOrganizationTenancy(fn () => FinanceTransaction::query()
            ->where('source_type', $sourceType)
            ->where('source_id', $sourceId)
            ->whereIn('type', [FinanceTransactionType::PaymentCollected->value, FinanceTransactionType::CodCollected->value])
            ->sum('amount'));
    }

    /**
     * Confirm a COD order's cash has been physically collected. Idempotent —
     * calling this twice for the same order returns the original
     * transaction rather than creating a second one.
     *
     * Gated on delivery: a cod_receivable_created transaction only means
     * cash is EXPECTED (written the moment the order was confirmed) — it
     * must not be markable as collected before the order has actually been
     * delivered. Checked BEFORE the idempotency short-circuit below is
     * consulted for anything other than "already collected via this exact
     * action" — an order already closed via a DIFFERENT mechanism (an
     * external settlement or courier deposit) must be rejected here too,
     * or this would double-count cash on top of that other entry.
     *
     * ALSO gated on WHO carried it — this is the single-order, ad-hoc,
     * "cash landed in the drawer right now" action, and must never become a
     * shortcut around a provider's payout period/reconciliation or a
     * courier's cash handover. Delivered-but-carried-by-someone-else orders
     * are still "collectable" in the broader sense (FinanceCodCollectabilityStatus
     * ::isCollectable(), used by resolveCollectableOrders() below for
     * settlement/deposit batch inclusion) — just never through THIS method.
     * Rejects with a ValidationException (a clean redirect-back-with-errors,
     * never a 500), and — critically — never reaches the code that records
     * a transaction or closes anything below this check.
     */
    public function markCodCollected(
        Order $order,
        FinanceAccount $account,
        ?User $actor,
        float $amount,
        CarbonInterface $collectedAt,
        ?string $reference = null,
        ?string $note = null,
    ): FinanceTransaction {
        // Repeating the SAME action on the SAME order is a harmless no-op —
        // return the original row instead of re-validating collectability
        // (which would now correctly read "settled", the normal state right
        // after the first call).
        if ($this->isCodCollected($order)) {
            return $this->transactions->record([
                'organization_id' => $account->organization_id,
                'store_id' => $order->store_id,
                'account_id' => $account->id,
                'direction' => FinanceTransactionDirection::In,
                'type' => FinanceTransactionType::CodCollected,
                'amount' => $amount,
                'currency' => $order->currency ?? 'MAD',
                'occurred_at' => $collectedAt,
                'source_type' => Order::class,
                'source_id' => $order->id,
                'reference' => $reference ?? $order->order_number,
                'description' => $note,
                'created_by' => $actor?->id,
            ]);
        }

        if (! $this->collectability->isDirectlyCollectable($order)) {
            throw ValidationException::withMessages([
                'order' => $this->collectability->directCollectionBlockedReason($order),
            ]);
        }

        return $this->transactions->record([
            'organization_id' => $account->organization_id,
            'store_id' => $order->store_id,
            'account_id' => $account->id,
            'direction' => FinanceTransactionDirection::In,
            'type' => FinanceTransactionType::CodCollected,
            'amount' => $amount,
            'currency' => $order->currency ?? 'MAD',
            'occurred_at' => $collectedAt,
            'source_type' => Order::class,
            'source_id' => $order->id,
            'reference' => $reference ?? $order->order_number,
            'description' => $note,
            'created_by' => $actor?->id,
        ]);
    }

    /**
     * Close a COD order's receivable as part of an external carrier
     * settlement or an internal courier cash deposit. Deliberately a
     * NEUTRAL ledger fact — no cash moves here, the actual cash entry is
     * booked exactly once, in aggregate, by the settlement/deposit itself
     * (FinanceCodSettlementService::settle() / FinanceCourierDepositService::confirm()).
     * Idempotent per (order, closing type) via the ledger's own unique index.
     */
    public function closeCodReceivable(Order $order, FinanceTransactionType $closingType, CarbonInterface $occurredAt, ?array $metadata = null): FinanceTransaction
    {
        $organization = $order->store?->organization;

        // Repeating the SAME closing action for the SAME order (settle()/
        // confirm() called twice) is idempotent — return the existing row
        // rather than re-validating collectability, which would now
        // correctly read "settled" (the expected state right after the
        // first call went through).
        $alreadyClosedThisWay = $this->transactions->alreadyRecorded(Order::class, $order->id, $closingType);

        if (! $alreadyClosedThisWay && ! $this->collectability->isCollectable($order)) {
            // Re-checked HERE (not just when the settlement/deposit draft
            // was created) — an order's delivery status can change while a
            // draft sits around, and this is the one choke point every
            // settle()/confirm() call funnels through.
            throw ValidationException::withMessages([
                'order_ids' => "Order {$order->order_number} can no longer be closed — " . lcfirst($this->collectability->assess($order)['reason']),
            ]);
        }

        return $this->transactions->record([
            'organization_id' => $organization?->id,
            'store_id' => $order->store_id,
            'direction' => FinanceTransactionDirection::Neutral,
            'type' => $closingType,
            'amount' => $order->total,
            'currency' => $order->currency ?? 'MAD',
            'occurred_at' => $occurredAt,
            'source_type' => Order::class,
            'source_id' => $order->id,
            'reference' => $order->order_number,
            'description' => match ($closingType) {
                FinanceTransactionType::CodSettledExternal => "COD settled via external carrier for order {$order->order_number}",
                FinanceTransactionType::CodClearedByCourier => "COD cleared via courier deposit for order {$order->order_number}",
                default => "COD receivable closed for order {$order->order_number}",
            },
            'metadata' => $metadata,
        ]);
    }

    public function isCodCollected(Order $order): bool
    {
        return $this->transactions->alreadyRecorded(Order::class, $order->id, FinanceTransactionType::CodCollected);
    }

    /**
     * Orders with a pending COD receivable (created, not yet collected) for
     * the given organization — the data source for the COD Receivables page.
     */
    public function pendingCodOrderIds(string $organizationId): Collection
    {
        return FinanceTransaction::withoutOrganizationTenancy(function () use ($organizationId) {
            // "Closed" covers every way a receivable can stop being pending:
            // the ad-hoc single-order mark-collected action, an external
            // carrier settlement, or an internal courier cash deposit.
            $collectedIds = FinanceTransaction::query()
                ->where('organization_id', $organizationId)
                ->whereIn('type', FinanceTransactionType::codClosingTypes())
                ->pluck('source_id');

            return FinanceTransaction::query()
                ->where('organization_id', $organizationId)
                ->where('type', FinanceTransactionType::CodReceivableCreated->value)
                ->whereNotIn('source_id', $collectedIds)
                ->pluck('source_id');
        });
    }

    /**
     * Validate and resolve a batch of order ids submitted for an external
     * COD settlement or an internal courier deposit. Every id must be a
     * genuinely pending COD receivable for this organization AND the order
     * must have actually been DELIVERED — a cod_receivable_created
     * transaction only means cash is expected, written the moment the
     * order was confirmed; COD money cannot be settled/deposited before
     * delivery no matter how "pending" the receivable already looks in the
     * ledger. The single shared gate for both FinanceCodSettlementService
     * and FinanceCourierDepositService, so neither can drift out of sync
     * with the other on this rule.
     *
     * @param  array<int, string>  $orderIds
     * @return Collection<int, Order>
     *
     * @throws ValidationException
     */
    public function resolveCollectableOrders(Organization $organization, array $orderIds): Collection
    {
        $orderIds = array_values(array_unique(array_filter($orderIds)));

        if ($orderIds === []) {
            throw ValidationException::withMessages(['order_ids' => 'Select at least one pending COD order.']);
        }

        $pendingIds = $this->pendingCodOrderIds($organization->id);
        $invalid = array_diff($orderIds, $pendingIds->all());

        if ($invalid !== []) {
            throw ValidationException::withMessages(['order_ids' => 'One or more selected orders are not pending COD receivables for this organization.']);
        }

        // Finance is organization-scoped; Order carries a store-level
        // TenantScope, so this must bypass it the same way the COD
        // Receivables controller does — the org-membership check above is
        // the real tenant guard.
        $orders = Order::withoutTenancy(fn () => Order::query()->whereIn('id', $orderIds)->with(['shipment.provider', 'orderShipment.agent'])->get());

        if ($orders->count() !== count($orderIds)) {
            throw ValidationException::withMessages(['order_ids' => 'One or more selected orders could not be found.']);
        }

        $notCollectable = $orders->reject(fn (Order $order) => $this->collectability->isCollectable($order));

        if ($notCollectable->isNotEmpty()) {
            throw ValidationException::withMessages([
                'order_ids' => 'The following orders have not been delivered yet and cannot be settled/deposited: '
                    . $notCollectable->map(fn (Order $order) => $order->order_number)->implode(', '),
            ]);
        }

        return $orders;
    }

    /**
     * Heuristic payment classification — this codebase has no normalized
     * "payment method" column on orders yet (Shopify/WooCommerce/YouCan
     * orders keep only the raw platform payload in platform_data). Rather
     * than add a column and touch every connector/mapper for a Phase 2
     * foundation, this reads the same raw payload other Finance-adjacent
     * code already treats as the source of truth, and falls back to COD —
     * this app's dominant channel (see the WhatsApp COD confirmation flow) —
     * whenever the platform's raw shape doesn't say otherwise. Safe by
     * design: misclassifying a prepaid order as COD only means it waits for
     * a manual "mark collected," never that money is fabricated as already in.
     */
    private function isLikelyCod(Order $order): bool
    {
        $data = $order->platform_data ?? [];

        // Shopify: financial_status is authoritative.
        if (isset($data['financial_status'])) {
            return ! in_array($data['financial_status'], ['paid', 'partially_paid'], true);
        }

        // WooCommerce: explicit payment_method code, or paid-date presence.
        if (isset($data['payment_method'])) {
            if (strtolower((string) $data['payment_method']) === 'cod') {
                return true;
            }

            return blank($data['date_paid'] ?? null);
        }

        return true;
    }

    private function posAccountType(?string $paymentMethod): FinanceAccountType
    {
        return match ($paymentMethod) {
            'card' => FinanceAccountType::Card,
            'cash' => FinanceAccountType::Cash,
            default => FinanceAccountType::Cash,
        };
    }
}
