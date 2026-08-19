<?php

declare(strict_types=1);

namespace App\Services\Pos;

use App\Models\PosSession;
use App\Models\Store;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use RuntimeException;
use Throwable;

class SessionManagementService
{
    /**
     * Open a new POS session for a cashier. Fails if the cashier already has an open one.
     */
    public function openSession(Store $store, User $cashier, float $openingBalance, ?string $notes = null): PosSession
    {
        if ($openingBalance < 0) {
            throw new RuntimeException('Opening balance cannot be negative.');
        }

        try {
            return DB::transaction(function () use ($store, $cashier, $openingBalance, $notes) {
                $existing = PosSession::query()
                    ->where('store_id', $store->id)
                    ->where('cashier_id', $cashier->id)
                    ->where('status', 'open')
                    ->lockForUpdate()
                    ->first();

                if ($existing !== null) {
                    throw new RuntimeException('An open session already exists for this cashier.');
                }

                $session = PosSession::create([
                    'store_id'        => $store->id,
                    'cashier_id'      => $cashier->id,
                    'status'          => 'open',
                    'opening_balance' => $openingBalance,
                    'opened_at'       => now(),
                    'notes'           => $notes,
                ]);

                Log::info('POS session opened', [
                    'session_id'      => $session->id,
                    'store_id'        => $store->id,
                    'cashier_id'      => $cashier->id,
                    'opening_balance' => $openingBalance,
                ]);

                return $session;
            });
        } catch (RuntimeException $e) {
            throw $e;
        } catch (Throwable $e) {
            Log::error('Failed to open POS session', [
                'store_id'   => $store->id,
                'cashier_id' => $cashier->id,
                'error'      => $e->getMessage(),
            ]);

            throw new RuntimeException('Could not open session: ' . $e->getMessage(), 0, $e);
        }
    }

    /**
     * Close an open POS session. Stores closing_balance/closed_at/closing_notes
     * and returns the refreshed model.
     */
    public function closeSession(PosSession $session, float $closingBalance, ?string $closingNotes = null): PosSession
    {
        if ($session->status !== 'open') {
            throw new RuntimeException('Session is not open.');
        }

        if ($closingBalance < 0) {
            throw new RuntimeException('Closing balance cannot be negative.');
        }

        try {
            return DB::transaction(function () use ($session, $closingBalance, $closingNotes) {
                $session->update([
                    'status'          => 'closed',
                    'closing_balance' => $closingBalance,
                    'closed_at'       => now(),
                    'closing_notes'   => $closingNotes,
                ]);

                $fresh = $session->fresh();

                Log::info('POS session closed', [
                    'session_id'      => $fresh->id,
                    'closing_balance' => $closingBalance,
                    'discrepancy'     => $this->validateSessionBalance($fresh),
                ]);

                return $fresh;
            });
        } catch (RuntimeException $e) {
            throw $e;
        } catch (Throwable $e) {
            Log::error('Failed to close POS session', [
                'session_id' => $session->id,
                'error'      => $e->getMessage(),
            ]);

            throw new RuntimeException('Could not close session: ' . $e->getMessage(), 0, $e);
        }
    }

    /**
     * Return the cash discrepancy between expected and actual closing balance.
     *
     *  expected = opening_balance + total_sales - total_refunds
     *  actual   = closing_balance
     *  discrepancy = actual - expected   (positive = over, negative = short)
     *
     * Returns 0.0 when the balance matches exactly, when the session is still
     * open, or when no closing balance has been recorded yet.
     */
    public function validateSessionBalance(PosSession $session): float
    {
        if ($session->closing_balance === null) {
            return 0.0;
        }

        $expected = (float) $session->opening_balance
                  + (float) $session->total_sales
                  - (float) $session->total_refunds;

        $actual = (float) $session->closing_balance;

        $discrepancy = round($actual - $expected, 2);

        return $discrepancy === 0.0 ? 0.0 : $discrepancy;
    }
}
