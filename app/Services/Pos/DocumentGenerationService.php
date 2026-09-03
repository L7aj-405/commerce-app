<?php

declare(strict_types=1);

namespace App\Services\Pos;

use App\Enums\FulfillmentDocumentType;
use App\Models\Facture;
use App\Models\FinanceExpense;
use App\Models\Order;
use App\Models\PosOrder;
use App\Models\User;
use App\Services\Documents\DocumentRenderer;
use App\Services\Documents\DocumentTemplateResolver;
use Illuminate\Support\Collection;
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
    private const INTERNAL_VOUCHER_VIEW = 'documents.internal-voucher';
    private const CARRIER_LABEL_VIEW   = 'documents.carrier-label';

    public function __construct(
        private readonly DocumentTemplateResolver $templates = new DocumentTemplateResolver(),
        private readonly DocumentRenderer $renderer = new DocumentRenderer(),
    ) {}

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
     * Render an A6 internal fallback carrier label and return the raw PDF
     * bytes. Used ONLY when the official provider label PDF cannot be
     * fetched — it carries a clear "not official" badge. Not persisted here;
     * FulfillmentDocumentService stores the bytes.
     *
     * @param  array<string, mixed>  $label  payload from DeliveryNoteService::fallbackLabelPayload()
     */
    public function renderCarrierFallbackLabel(array $label): string
    {
        try {
            $html = View::make(self::CARRIER_LABEL_VIEW, ['label' => $label])->render();

            $tempDir = storage_path('app/mpdf-tmp');
            if (! is_dir($tempDir)) {
                @mkdir($tempDir, 0775, true);
            }

            $mpdf = new Mpdf([
                'tempDir'       => $tempDir,
                'mode'          => 'utf-8',
                'format'        => [105, 148], // A6 portrait
                'margin_left'   => 6,
                'margin_right'  => 6,
                'margin_top'    => 6,
                'margin_bottom' => 6,
                'default_font'  => 'dejavusans',
            ]);
            $mpdf->WriteHTML($html);

            return $mpdf->Output('', 'S');
        } catch (Throwable $e) {
            Log::error('Carrier fallback label generation failed', [
                'tracking_number' => $label['tracking_number'] ?? null,
                'error'           => $e->getMessage(),
            ]);

            throw new RuntimeException('Failed to generate carrier fallback label: ' . $e->getMessage(), 0, $e);
        }
    }

    /**
     * Render the INTERNAL pick/pack ticket for one online order and return
     * the raw PDF bytes. Routed through the DocumentTemplate abstraction so a
     * store/organization can later override paper size, margins, header/
     * footer, visible fields, barcode position and text labels without any
     * code change — until then the system default (config/documents.php +
     * resources/views/documents/pick-pack-ticket.blade.php) is used.
     *
     * No provider API call, no finance transaction, no inventory side effect,
     * no order-status change — this is a read-only render of the order's own
     * stored data.
     */
    public function renderPickPackTicket(Order $order, ?User $actor = null): string
    {
        try {
            $template = $this->templates->resolve(
                FulfillmentDocumentType::PickTicket->value,
                $order->store?->organization,
                $order->store_id,
            );

            return $this->renderer->render($template, $this->pickPackTicketData($order, $actor));
        } catch (Throwable $e) {
            Log::error('Pick/pack ticket generation failed', ['order_id' => $order->id, 'error' => $e->getMessage()]);

            throw new RuntimeException('Failed to generate pick/pack ticket: ' . $e->getMessage(), 0, $e);
        }
    }

    /**
     * One combined PDF for several online orders — one order per page.
     *
     * @param  Collection<int, Order>  $orders
     */
    public function renderPickPackTicketBatch(Collection $orders, ?User $actor = null): string
    {
        if ($orders->isEmpty()) {
            throw new RuntimeException('No orders were given for the pick/pack ticket batch.');
        }

        try {
            $first = $orders->first();
            $template = $this->templates->resolve(
                FulfillmentDocumentType::PickTicket->value,
                $first->store?->organization,
                $first->store_id,
            );

            $items = $orders->map(fn (Order $o) => $this->pickPackTicketData($o, $actor))->all();

            return $this->renderer->renderBatch($template, $items);
        } catch (Throwable $e) {
            Log::error('Pick/pack ticket batch generation failed', ['count' => $orders->count(), 'error' => $e->getMessage()]);

            throw new RuntimeException('Failed to generate the pick/pack ticket batch: ' . $e->getMessage(), 0, $e);
        }
    }

    /**
     * Everything the pick/pack ticket prints — built ONLY from the order's
     * own columns and its `items` JSON snapshot, so a missing product/
     * variant relation or SKU never causes a failure.
     *
     * @return array<string, mixed>
     */
    public function pickPackTicketData(Order $order, ?User $actor = null): array
    {
        $order->loadMissing(['store.organization', 'shippingCity', 'inventoryAllocation.warehouse']);

        $platformData = is_array($order->platform_data) ? $order->platform_data : [];
        $financialStatus = strtolower((string) ($platformData['financial_status']
            ?? $platformData['payment_status']
            ?? ($platformData['payment']['status'] ?? '')));
        $isPrepaid = in_array($financialStatus, ['paid', 'prepaid', 'captured', 'authorized'], true);

        $items = $this->pickPackTicketItems($order);
        $unitsTotal = array_sum(array_map(static fn (array $it) => (int) ($it['quantity'] ?? 0), $items));

        return [
            'store_name' => $order->store?->name,
            'organization_name' => $order->store?->organization?->name,
            'warehouse_name' => $order->inventoryAllocation?->warehouse?->name,
            'order_reference' => (string) ($order->order_number ?? $order->id),
            'order_id' => $order->id,
            'order_date' => optional($order->created_at)->format('Y-m-d H:i'),
            'printed_at' => now()->format('Y-m-d H:i'),
            'printed_by' => $actor?->name,
            'customer_name' => $order->customer_name ?: null,
            'phone' => $order->customer_phone ?: null,
            'city' => $order->shippingCity?->name,
            'address' => $order->confirmed_shipping_address ?: null,
            'notes' => $order->notes ?: null,
            'payment_method' => $isPrepaid ? 'Prepaid (paid online)' : 'Cash on delivery (COD)',
            'is_prepaid' => $isPrepaid,
            'cod_amount' => $isPrepaid ? 0.0 : (float) $order->total,
            'currency' => $order->currency ?? 'MAD',
            'items' => $items,
            'items_count' => count($items),
            'units_total' => $unitsTotal,
        ];
    }

    /**
     * The order's line items, straight from `orders.items` JSON — never
     * resolved through the inventory resolver (that would be an unwanted
     * side effect on a plain print). Tolerates every connector's key
     * variations.
     *
     * @return array<int, array{name: string, variant: ?string, sku: ?string, barcode: ?string, quantity: int}>
     */
    private function pickPackTicketItems(Order $order): array
    {
        $raw = is_array($order->items) ? $order->items : [];

        return array_values(array_map(function ($item): array {
            $item = is_array($item) ? $item : [];

            $variant = $item['variant'] ?? $item['variant_title'] ?? $item['variation'] ?? null;

            if ($variant === null && ! empty($item['options']) && is_array($item['options'])) {
                $parts = [];
                foreach ($item['options'] as $key => $value) {
                    if (is_array($value)) {
                        $label = $value['name'] ?? $value['key'] ?? null;
                        $val = $value['value'] ?? $value['option'] ?? null;
                        $parts[] = trim(($label ? "{$label}: " : '') . (string) $val);
                    } elseif (is_string($key) && ! is_int($key)) {
                        $parts[] = "{$key}: {$value}";
                    } else {
                        $parts[] = (string) $value;
                    }
                }
                $variant = implode(' / ', array_filter($parts));
            }

            return [
                'name' => (string) ($item['name'] ?? $item['title'] ?? $item['product_name'] ?? 'Item'),
                'variant' => ($variant !== null && $variant !== '') ? (string) $variant : null,
                'sku' => $this->firstFilled($item, ['sku', 'product_sku', 'variant_sku']),
                'barcode' => $this->firstFilled($item, ['barcode', 'ean', 'ean13', 'upc', 'gtin']),
                'quantity' => max(1, (int) ($item['quantity'] ?? $item['qty'] ?? 1)),
            ];
        }, $raw));
    }

    /**
     * @param  array<string, mixed>  $item
     * @param  array<int, string>  $keys
     */
    private function firstFilled(array $item, array $keys): ?string
    {
        foreach ($keys as $key) {
            if (isset($item[$key]) && $item[$key] !== '' && $item[$key] !== null) {
                return (string) $item[$key];
            }
        }

        return null;
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
     * Render a printable internal cash voucher for an expense with no
     * official invoice — the internal justification an accountant/owner can
     * sign, scan, and re-attach as a `internal_voucher` document (see
     * FinanceExpenseService's class docblock). NOT persisted — always
     * reflects the expense's CURRENT state (amount, justification fields,
     * owner-review status), so a re-print after an edit or a review action
     * is never stale. Never itself a FinanceDocument or a finance_transaction
     * — printing is purely a paper trail aid, uploading the signed scan back
     * is a separate, explicit action.
     */
    public function renderInternalVoucherPdf(FinanceExpense $expense): string
    {
        $expense->loadMissing(['organization', 'store', 'category', 'createdBy', 'ownerReviewedBy']);

        try {
            $html = View::make(self::INTERNAL_VOUCHER_VIEW, ['expense' => $expense])->render();

            $mpdf = $this->makeMpdf(width: 210, isReceipt: false); // A4
            $mpdf->WriteHTML($html);

            return $mpdf->Output('', 'S');
        } catch (Throwable $e) {
            Log::error('Internal voucher PDF generation failed', [
                'expense_id' => $expense->id,
                'error'      => $e->getMessage(),
            ]);

            throw new RuntimeException('Failed to generate internal voucher PDF: ' . $e->getMessage(), 0, $e);
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
