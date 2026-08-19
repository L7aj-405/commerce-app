<?php

declare(strict_types=1);

namespace App\Services\Invoicing;

use App\Contracts\Invoiceable;
use App\Models\Facture;
use App\Models\FactureItem;
use App\Models\User;
use App\Services\Pos\DocumentGenerationService;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Owns every state transition in the invoice lifecycle so controllers stay thin.
 *
 *   draft ─issue→ issued ─send→ sent ─pay→ paid
 *                    └─────────── void ───────────┘
 *
 * Issuing finalizes the invoice: it snapshots the source order's line items,
 * generates the PDF, and locks the record. After that only amend()/void()
 * (which the FacturePolicy gates behind `invoices.amend`/`invoices.void`) may
 * change it, and every such change is written to the activity log with a reason.
 */
class InvoiceService
{
    public function __construct(private readonly DocumentGenerationService $documents)
    {
    }

    /**
     * Issue (finalize) an invoice for an order. Idempotent per source: a source
     * that already has an invoice returns the existing one instead of duplicating.
     *
     * @param  array{due_date?: ?string, notes?: ?string, generate_pdf?: bool}  $options
     */
    public function issueFor(Invoiceable $source, User $issuer, array $options = []): Facture
    {
        if ($existing = $source->invoice()->first()) {
            return $existing;
        }

        $totals   = $source->invoiceTotals();
        $customer = $source->invoiceCustomer();

        $facture = DB::transaction(function () use ($source, $issuer, $options, $totals, $customer): Facture {
            $facture = new Facture([
                'store_id'         => $source->invoiceStoreId(),
                'invoice_number'   => $this->generateInvoiceNumber($source->invoiceStoreId()),
                'status'           => Facture::STATUS_ISSUED,
                'payment_status'   => 'unpaid',
                'invoice_date'     => now()->toDateString(),
                'due_date'         => $options['due_date'] ?? null,
                'issued_at'        => now(),
                'issued_by'        => $issuer->id,
                'locked_at'        => now(),
                'customer_name'    => $customer['name'],
                'customer_email'   => $customer['email'],
                'customer_phone'   => $customer['phone'],
                'customer_address' => $customer['address'],
                'subtotal'         => $totals['subtotal'],
                'tax_amount'       => $totals['tax_amount'],
                'discount_amount'  => $totals['discount_amount'],
                'total_amount'     => $totals['total_amount'],
                'amount_paid'      => 0,
                'payment_method'   => $source->invoicePaymentMethod(),
                'notes'            => $options['notes'] ?? null,
            ]);

            $facture->invoiceable()->associate($source);

            // Preserve legacy FK when the source is a PosOrder.
            if ($source instanceof \App\Models\PosOrder) {
                $facture->pos_order_id = $source->id;
            }

            $facture->save();

            foreach ($source->invoiceLineItems() as $line) {
                FactureItem::create(['facture_id' => $facture->id] + $line);
            }

            return $facture;
        });

        if ($options['generate_pdf'] ?? true) {
            $this->documents->generateInvoicePdf($facture);
        }

        return $facture->fresh(['items', 'store']);
    }

    public function markSent(Facture $facture, User $actor): Facture
    {
        $this->assertFinalized($facture, 'send');

        // Automatic LogsActivity captures the status old→new for this update.
        $facture->update([
            'status'  => Facture::STATUS_SENT,
            'sent_at' => now(),
        ]);

        return $facture;
    }

    public function recordPayment(Facture $facture, User $actor, float $amount): Facture
    {
        $this->assertFinalized($facture, 'record payment on');

        $paid   = round((float) $facture->amount_paid + $amount, 2);
        $total  = (float) $facture->total_amount;
        $isPaid = $paid >= $total;

        $facture->update([
            'amount_paid'    => $paid,
            'payment_status' => $isPaid ? 'paid' : 'partial',
            'status'         => $isPaid ? Facture::STATUS_PAID : $facture->status,
            'paid_at'        => $isPaid ? now() : $facture->paid_at,
        ]);

        return $facture;
    }

    /**
     * Amend a finalized invoice. Requires an explicit reason (persisted to the
     * activity log). Authorization (`invoices.amend`) is enforced by FacturePolicy
     * before this is called — the service is the last line of integrity defense.
     *
     * @param  array<string, mixed>  $data
     */
    public function amend(Facture $facture, User $actor, array $data, string $reason): Facture
    {
        if (trim($reason) === '') {
            throw new RuntimeException('An amendment reason is required.');
        }

        if ($facture->isVoid()) {
            throw new RuntimeException('A voided invoice cannot be amended.');
        }

        $allowed = array_intersect_key($data, array_flip([
            'customer_name', 'customer_email', 'customer_phone', 'customer_address',
            'due_date', 'notes',
        ]));

        $old = $facture->only(array_keys($allowed));

        // Suppress the automatic entry so the amendment is a single, complete
        // audit record carrying old, new AND the mandatory reason together.
        $facture->disableLogging();
        $facture->update($allowed);
        $facture->enableLogging();

        $this->logChange($facture, $actor, 'amended', $old, $facture->only(array_keys($allowed)), $reason);

        return $facture->fresh();
    }

    public function void(Facture $facture, User $actor, string $reason): Facture
    {
        if (trim($reason) === '') {
            throw new RuntimeException('A void reason is required.');
        }

        if ($facture->isVoid()) {
            return $facture;
        }

        $old = ['status' => $facture->status];

        $facture->disableLogging();
        $facture->update([
            'status'      => Facture::STATUS_VOID,
            'voided_at'   => now(),
            'void_reason' => $reason,
        ]);
        $facture->enableLogging();

        $this->logChange($facture, $actor, 'voided', $old, ['status' => Facture::STATUS_VOID], $reason);

        return $facture;
    }

    /** Write a single, reason-tagged audit entry with explicit old/new values. */
    private function logChange(Facture $facture, User $actor, string $event, array $old, array $new, string $reason): void
    {
        activity('invoice')
            ->performedOn($facture)
            ->causedBy($actor)
            ->event($event)
            ->withProperties(['old' => $old, 'attributes' => $new, 'reason' => $reason])
            ->log("invoice {$event}");
    }

    public function regeneratePdf(Facture $facture): string
    {
        return $this->documents->generateInvoicePdf($facture);
    }

    private function assertFinalized(Facture $facture, string $action): void
    {
        if (! $facture->isFinalized()) {
            throw new RuntimeException("Cannot {$action} an invoice that is not issued.");
        }
    }

    /** {STORE_PREFIX}-INV-{YYYYMMDD}-{0001}, sequence resets daily per store. */
    private function generateInvoiceNumber(string $storeId): string
    {
        $date = now()->format('Ymd');

        return DB::transaction(function () use ($storeId, $date): string {
            $count = Facture::query()
                ->where('store_id', $storeId)
                ->whereDate('created_at', now()->toDateString())
                ->lockForUpdate()
                ->count();

            $sequence = str_pad((string) ($count + 1), 4, '0', STR_PAD_LEFT);

            return "INV-{$date}-{$sequence}";
        });
    }
}
