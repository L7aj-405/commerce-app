<?php

declare(strict_types=1);

namespace App\Listeners;

use App\Events\OrderCreated;
use App\Services\Finance\FinanceOrderTransactionService;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Bridges every newly-synced/imported online order into the Finance ledger.
 * Deliberately defensive: a Finance-side bug must never break order
 * import/sync, so any failure here is logged and swallowed, never rethrown.
 */
class SyncFinanceOrderTransactions
{
    public function __construct(private readonly FinanceOrderTransactionService $finance) {}

    public function handle(OrderCreated $event): void
    {
        try {
            $this->finance->syncOrderFinancials($event->order);
        } catch (Throwable $e) {
            Log::error('Finance: failed to sync order financials', [
                'order_id' => $event->order->id,
                'error' => $e->getMessage(),
            ]);
        }
    }
}
