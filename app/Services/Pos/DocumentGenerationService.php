<?php

declare(strict_types=1);

namespace App\Services\Pos;

use App\Models\Facture;
use App\Models\Order;
use App\Models\PosOrder;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\View;
use Mpdf\Mpdf;
use RuntimeException;
use Throwable;

class DocumentGenerationService
{
    private const RECEIPT_WIDTH_MM = 80;
    private const RECEIPT_VIEW         = 'pos.documents.receipt';
    private const INVOICE_VIEW         = 'pos.documents.invoice';
    private const FACTURE_VIEW         = 'documents.facture';
    private const FACTURE_RECEIPT_VIEW = 'documents.facture-receipt';
    private const ONLINE_RECEIPT_VIEW  = 'documents.online-receipt';
    private const MANIFEST_VIEW        = 'documents.manifest';
    private const BON_DE_SORTIE_VIEW   = 'documents.bon-de-sortie';

    /**
     * Render a finalized Facture to an A4 PDF and persist it. Returns the
     * storage-relative path and stamps it onto the facture's pdf_path column.
     * This is the unified invoice PDF used for both POS and deferred orders.
     */
    public function generateInvoicePdf(Facture $facture): string
    {
        $facture->loadMissing(['items', 'store']);

        $date         = $facture->invoice_date?->format('Y/m/d') ?? now()->format('Y/m/d');
        $relativePath = sprintf('invoices/%s/%s/%s.pdf', $facture->store_id, $date, $facture->invoice_number);

        try {
            $html = View::make(self::FACTURE_VIEW, ['facture' => $facture])->render();

            $mpdf = $this->makeMpdf(width: 210, isReceipt: false);
            $mpdf->WriteHTML($html);
            $pdf = $mpdf->Output('', 'S');

            Storage::disk('local')->put($relativePath, $pdf);
            $facture->forceFill(['pdf_path' => $relativePath])->saveQuietly();

            Log::info('Invoice PDF generated', [
                'facture_id'     => $facture->id,
                'invoice_number' => $facture->invoice_number,
                'path'           => $relativePath,
            ]);

            return $relativePath;
        } catch (Throwable $e) {
            Log::error('Invoice PDF generation failed', [
                'facture_id' => $facture->id,
                'error'      => $e->getMessage(),
            ]);

            throw new RuntimeException('Failed to generate invoice PDF: ' . $e->getMessage(), 0, $e);
        }
    }

    /**
     * Render an 80 mm thermal receipt for a finalized invoice and return the raw
     * PDF bytes. Not persisted — this backs an on-demand "print receipt" action,
     * so it always reflects the invoice's current state.
     */
    public function renderFactureReceipt(Facture $facture): string
    {
        $facture->loadMissing(['items', 'store']);

        try {
            $html = View::make(self::FACTURE_RECEIPT_VIEW, ['facture' => $facture])->render();

            $mpdf = $this->makeMpdf(width: self::RECEIPT_WIDTH_MM, isReceipt: true);
            $mpdf->WriteHTML($html);

            return $mpdf->Output('', 'S');
        } catch (Throwable $e) {
            Log::error('Invoice receipt generation failed', [
                'facture_id' => $facture->id,
                'error'      => $e->getMessage(),
            ]);

            throw new RuntimeException('Failed to generate invoice receipt: ' . $e->getMessage(), 0, $e);
        }
    }

    /**
     * Render an 80 mm thermal receipt for an ONLINE order and return the raw PDF
     * bytes. Not persisted — backs the on-demand "print receipt" action so a
     * driver can print a slip to attach to the parcel without first cutting a
     * formal invoice. Line items and totals come from the Invoiceable contract,
     * so this stays in sync with what the invoice would show.
     */
    public function renderOnlineReceipt(Order $order): string
    {
        $order->loadMissing('store');

        try {
            $html = View::make(self::ONLINE_RECEIPT_VIEW, [
                'order'  => $order,
                'items'  => $order->invoiceLineItems(),
                'totals' => $order->invoiceTotals(),
            ])->render();

            $mpdf = $this->makeMpdf(width: self::RECEIPT_WIDTH_MM, isReceipt: true);
            $mpdf->WriteHTML($html);

            return $mpdf->Output('', 'S');
        } catch (Throwable $e) {
            Log::error('Online order receipt render failed', [
                'order_id' => $order->id,
                'error'    => $e->getMessage(),
            ]);

            throw new RuntimeException('Failed to render online receipt: ' . $e->getMessage(), 0, $e);
        }
    }

    /**
     * Render an A4 delivery manifest for a carrier handover and return the raw
     * PDF bytes. Not persisted — a manifest is rebuilt on demand so a re-print
     * reflects the batch as it currently stands.
     *
     * @param  array<string, mixed>  $manifest  payload from DispatchService::gatherManifest()
     */
    public function renderManifest(array $manifest): string
    {
        try {
            $html = View::make(self::MANIFEST_VIEW, ['manifest' => $manifest])->render();

            $mpdf = $this->makeMpdf(width: 210, isReceipt: false); // A4
            $mpdf->WriteHTML($html);

            return $mpdf->Output('', 'S');
        } catch (Throwable $e) {
            Log::error('Manifest PDF generation failed', [
                'reference' => $manifest['reference'] ?? null,
                'error'     => $e->getMessage(),
            ]);

            throw new RuntimeException('Failed to generate manifest PDF: ' . $e->getMessage(), 0, $e);
        }
    }

    /**
     * Render an A4 "Bon de Sortie" (stock exit slip) and return the raw PDF bytes.
     * Not persisted — rebuilt on demand so a re-print reflects the transfer as it
     * currently stands. Backs the inline-print / download actions.
     *
     * @param  array<string, mixed>  $slip  payload from StockTransferController::slipPayload()
     */
    public function renderBonDeSortie(array $slip): string
    {
        try {
            $html = View::make(self::BON_DE_SORTIE_VIEW, ['slip' => $slip])->render();

            $mpdf = $this->makeMpdf(width: 210, isReceipt: false); // A4
            $mpdf->WriteHTML($html);

            return $mpdf->Output('', 'S');
        } catch (Throwable $e) {
            Log::error('Bon de Sortie PDF generation failed', [
                'reference' => $slip['reference'] ?? null,
                'error'     => $e->getMessage(),
            ]);

            throw new RuntimeException('Failed to generate Bon de Sortie PDF: ' . $e->getMessage(), 0, $e);
        }
    }

    /**
     * Render an 80 mm thermal receipt for the order and return the raw PDF bytes.
     * Not persisted — backs the on-demand "print receipt" action so it always
     * reflects the order's current state.
     */
    public function renderReceipt(PosOrder $order): string
    {
        $order->loadMissing(['items', 'store', 'cashier', 'session']);

        try {
            $html = View::make(self::RECEIPT_VIEW, ['order' => $order])->render();

            $mpdf = $this->makeMpdf(width: self::RECEIPT_WIDTH_MM, isReceipt: true);
            $mpdf->WriteHTML($html);

            return $mpdf->Output('', 'S');
        } catch (Throwable $e) {
            Log::error('POS receipt render failed', [
                'order_id' => $order->id,
                'error'    => $e->getMessage(),
            ]);

            throw new RuntimeException('Failed to render receipt: ' . $e->getMessage(), 0, $e);
        }
    }

    /**
     * Render an 80 mm thermal-style PDF receipt for the order and persist it.
     * Returns the storage-relative path (so Storage::get($path) works) and also
     * writes it onto the order's receipt_path column.
     */
    public function generateReceipt(PosOrder $order): string
    {
        $order->loadMissing(['items', 'store', 'cashier', 'session']);

        $relativePath = $this->buildPath('receipts', $order, 'pdf');

        try {
            $html = View::make(self::RECEIPT_VIEW, ['order' => $order])->render();

            $mpdf = $this->makeMpdf(width: self::RECEIPT_WIDTH_MM, isReceipt: true);
            $mpdf->WriteHTML($html);
            $pdf = $mpdf->Output('', 'S');

            Storage::disk('local')->put($relativePath, $pdf);

            $order->update(['receipt_path' => $relativePath]);

            Log::info('POS receipt generated', [
                'order_id'       => $order->id,
                'receipt_number' => $order->receipt_number,
                'path'           => $relativePath,
            ]);

            return $relativePath;
        } catch (Throwable $e) {
            Log::error('Receipt generation failed', [
                'order_id' => $order->id,
                'error'    => $e->getMessage(),
            ]);

            throw new RuntimeException('Failed to generate receipt: ' . $e->getMessage(), 0, $e);
        }
    }

    /**
     * Render a full A4 invoice PDF (customer-facing) for the order.
     */
    public function generateInvoice(PosOrder $order): string
    {
        $order->loadMissing(['items', 'store', 'cashier']);

        $relativePath = $this->buildPath('invoices', $order, 'pdf');

        try {
            $html = View::make(self::INVOICE_VIEW, ['order' => $order])->render();

            $mpdf = $this->makeMpdf(width: 210, isReceipt: false); // A4
            $mpdf->WriteHTML($html);
            $pdf = $mpdf->Output('', 'S');

            Storage::disk('local')->put($relativePath, $pdf);

            $order->update(['invoice_path' => $relativePath]);

            Log::info('POS invoice generated', [
                'order_id'       => $order->id,
                'receipt_number' => $order->receipt_number,
                'path'           => $relativePath,
            ]);

            return $relativePath;
        } catch (Throwable $e) {
            Log::error('Invoice generation failed', [
                'order_id' => $order->id,
                'error'    => $e->getMessage(),
            ]);

            throw new RuntimeException('Failed to generate invoice: ' . $e->getMessage(), 0, $e);
        }
    }

    private function buildPath(string $type, PosOrder $order, string $ext): string
    {
        $date = $order->created_at?->format('Y/m/d') ?? now()->format('Y/m/d');

        return sprintf('%s/%s/%s/%s.%s', $type, $order->store_id, $date, $order->receipt_number, $ext);
    }

    private function makeMpdf(float $width, bool $isReceipt): Mpdf
    {
        $tempDir = storage_path('app/mpdf-tmp');

        if (! is_dir($tempDir)) {
            @mkdir($tempDir, 0775, true);
        }

        $config = [
            'tempDir'      => $tempDir,
            'mode'         => 'utf-8',
            'format'       => $isReceipt ? [$width, 297] : 'A4',
            'margin_left'  => $isReceipt ? 3 : 15,
            'margin_right' => $isReceipt ? 3 : 15,
            'margin_top'   => $isReceipt ? 5 : 15,
            'margin_bottom'=> $isReceipt ? 5 : 15,
            'default_font' => $isReceipt ? 'dejavusansmono' : 'dejavusans',
        ];

        return new Mpdf($config);
    }
}
