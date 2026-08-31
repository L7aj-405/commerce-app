<?php

declare(strict_types=1);

namespace App\Enums;

enum FinanceCodSettlementStatus: string
{
    case Draft = 'draft';
    case Settled = 'settled';
    case Cancelled = 'cancelled';

    // Provider-period reconciliation outcomes (App\Services\Finance\
    // FinanceCodSettlementService::settle()) — a legacy manual settlement
    // (no actual_received_amount entered) never reaches these, it always
    // resolves to Settled exactly as before.
    /** actual_received_amount differs from expected_net_amount and needs a note/investigation. */
    case Disputed = 'disputed';
    /** actual_received_amount is present but lower than expected and accepted as a partial payout. */
    case Partial = 'partial';

    public function label(): string
    {
        return match ($this) {
            self::Draft => 'Draft',
            self::Settled => 'Settled',
            self::Cancelled => 'Cancelled',
            self::Disputed => 'Disputed',
            self::Partial => 'Partial',
        };
    }
}
