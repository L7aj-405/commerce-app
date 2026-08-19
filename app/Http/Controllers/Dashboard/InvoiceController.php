<?php

declare(strict_types=1);

namespace App\Http\Controllers\Dashboard;

use App\Http\Controllers\Controller;
use App\Mail\InvoiceMail;
use App\Models\Facture;
use App\Models\Order;
use App\Models\PosOrder;
use App\Services\Invoicing\InvoiceService;
use App\Services\Pos\DocumentGenerationService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Throwable;

class InvoiceController extends Controller
{
    public function __construct(private readonly InvoiceService $invoices)
    {
    }

    /** Deferred invoicing: generate/finalize an invoice from an existing order. */
    public function store(Request $request): RedirectResponse
    {
        $this->authorize('invoices.issue');

        $validated = $request->validate([
            'source_type' => ['required', 'in:pos_order,order'],
            'source_id'   => ['required', 'string'],
            'due_date'    => ['nullable', 'date'],
            'notes'       => ['nullable', 'string', 'max:1000'],
        ]);

        $store  = $request->user()->getActiveStore();
        abort_if($store === null, 422, 'No active store.');

        $source = $validated['source_type'] === 'pos_order'
            ? PosOrder::where('store_id', $store->id)->with('items')->findOrFail($validated['source_id'])
            : Order::where('store_id', $store->id)->findOrFail($validated['source_id']);

        $facture = $this->invoices->issueFor($source, $request->user(), [
            'due_date' => $validated['due_date'] ?? null,
            'notes'    => $validated['notes'] ?? null,
        ]);

        return redirect()
            ->route('dashboard.factures.show', $facture->id)
            ->with('success', "Invoice {$facture->invoice_number} generated.");
    }

    /** Print/save: stream the invoice PDF. */
    public function download(Request $request, Facture $facture): StreamedResponse
    {
        $this->authorize('view', $facture);

        if ($facture->pdf_path === null || ! Storage::disk('local')->exists($facture->pdf_path)) {
            $this->invoices->regeneratePdf($facture);
            $facture->refresh();
        }

        return Storage::disk('local')->download($facture->pdf_path, "{$facture->invoice_number}.pdf");
    }

    /**
     * Email the invoice to the customer and mark it sent.
     *
     * Delivery is synchronous and guarded: the invoice is only marked "sent"
     * once the mailer actually accepts the message, so the status never claims a
     * delivery that failed. A resend simply runs this again (updating sent_at).
     */
    public function email(Request $request, Facture $facture): RedirectResponse
    {
        $this->authorize('view', $facture);

        if (empty($facture->customer_email)) {
            return back()->withErrors(['email' => 'This invoice has no customer email.']);
        }

        if ($facture->pdf_path === null || ! Storage::disk('local')->exists($facture->pdf_path)) {
            $this->invoices->regeneratePdf($facture);
            $facture->refresh();
        }

        $alreadySent = $facture->sent_at !== null;

        try {
            Mail::to($facture->customer_email)->send(new InvoiceMail($facture));
        } catch (Throwable $e) {
            Log::error('Invoice email failed', [
                'facture_id' => $facture->id,
                'email'      => $facture->customer_email,
                'error'      => $e->getMessage(),
            ]);

            return back()->withErrors([
                'email' => 'Could not send the invoice email. Please try again.',
            ]);
        }

        $this->invoices->markSent($facture, $request->user());

        $verb = $alreadySent ? 're-sent' : 'emailed';

        return back()->with('success', "Invoice {$verb} to {$facture->customer_email}.");
    }

    /** Stream an 80mm thermal receipt of the invoice for in-store printing. */
    public function receipt(Request $request, Facture $facture, DocumentGenerationService $documents): Response
    {
        $this->authorize('view', $facture);

        $pdf = $documents->renderFactureReceipt($facture);

        return response($pdf, 200, [
            'Content-Type'        => 'application/pdf',
            'Content-Disposition' => "inline; filename=\"{$facture->invoice_number}-receipt.pdf\"",
        ]);
    }

    /** Amend a finalized invoice — requires a reason; gated by invoices.amend. */
    public function amend(Request $request, Facture $facture): RedirectResponse
    {
        $this->authorize('amend', $facture);

        $validated = $request->validate([
            'reason'           => ['required', 'string', 'min:3', 'max:500'],
            'customer_name'    => ['nullable', 'string', 'max:255'],
            'customer_email'   => ['nullable', 'email', 'max:255'],
            'customer_phone'   => ['nullable', 'string', 'max:50'],
            'customer_address' => ['nullable', 'string', 'max:500'],
            'due_date'         => ['nullable', 'date'],
            'notes'            => ['nullable', 'string', 'max:1000'],
        ]);

        $reason = $validated['reason'];
        unset($validated['reason']);

        $this->invoices->amend($facture, $request->user(), $validated, $reason);
        $this->invoices->regeneratePdf($facture->refresh());

        return back()->with('success', 'Invoice amended and re-generated.');
    }

    public function void(Request $request, Facture $facture): RedirectResponse
    {
        $this->authorize('void', $facture);

        $validated = $request->validate(['reason' => ['required', 'string', 'min:3', 'max:500']]);

        $this->invoices->void($facture, $request->user(), $validated['reason']);

        return back()->with('success', "Invoice {$facture->invoice_number} voided.");
    }

    public function pay(Request $request, Facture $facture): RedirectResponse
    {
        $this->authorize('update', $facture);

        $validated = $request->validate(['amount' => ['required', 'numeric', 'min:0.01']]);

        $this->invoices->recordPayment($facture, $request->user(), (float) $validated['amount']);

        return back()->with('success', 'Payment recorded.');
    }
}
