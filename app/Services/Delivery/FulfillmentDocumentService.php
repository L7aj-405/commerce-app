<?php

declare(strict_types=1);

namespace App\Services\Delivery;

use App\Enums\FulfillmentDocumentStatus;
use App\Enums\FulfillmentDocumentType;
use App\Models\DeliveryNote;
use App\Models\FulfillmentDocument;
use App\Models\Order;
use App\Models\Shipment;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Throwable;

/**
 * The single write path for `fulfillment_documents`. Purely operational
 * paperwork storage — this NEVER touches finance_transactions/the ledger.
 *
 * Files land on a private disk (never "public") under
 * fulfillment/{org|store}/{segment}/{documentable_id}/{uuid}.pdf. One row
 * per (documentable, document_type, provider_code) — regenerating replaces
 * it in place rather than piling up duplicates.
 */
class FulfillmentDocumentService
{
    public function disk(): string
    {
        return (string) config('fulfillment.documents.disk', 'local');
    }

    /** Persist provider-fetched PDF bytes for a documentable. */
    public function storeFetchedPdf(Model $documentable, FulfillmentDocumentType $type, string $bytes, array $opts = []): FulfillmentDocument
    {
        return $this->writeBytes($documentable, $type, $bytes, FulfillmentDocumentStatus::Stored, $opts);
    }

    /** Persist SaaS-generated PDF bytes (fallback label, pick ticket, manifest). */
    public function storeGeneratedPdf(Model $documentable, FulfillmentDocumentType $type, string $bytes, array $opts = []): FulfillmentDocument
    {
        return $this->writeBytes($documentable, $type, $bytes, FulfillmentDocumentStatus::Generated, $opts);
    }

    /**
     * Try to pull a provider PDF URL server-side and store it. On ANY
     * failure — network error, non-2xx, an HTML login page, a non-PDF body —
     * records the URL as unfetchable and returns that row. Never throws:
     * the caller decides whether to fall back to an internal label.
     */
    public function fetchAndStore(Model $documentable, FulfillmentDocumentType $type, string $url, array $opts = []): FulfillmentDocument
    {
        try {
            $response = Http::connectTimeout((int) config('fulfillment.fetch.connect_timeout', 10))
                ->timeout((int) config('fulfillment.fetch.timeout', 30))
                ->withOptions(['allow_redirects' => ['track_redirects' => true]])
                ->get($url);
        } catch (Throwable $e) {
            report($e);

            return $this->recordUnfetchable($documentable, $type, $url, FulfillmentDocumentStatus::FetchFailed, $opts, [
                'error' => $e->getMessage(),
            ]);
        }

        $body = (string) $response->body();
        $contentType = strtolower((string) $response->header('Content-Type'));
        $looksHtml = str_contains($contentType, 'text/html') || str_starts_with(ltrim($body), '<');
        $isPdf = str_contains($contentType, 'application/pdf') || str_starts_with($body, '%PDF');

        if ($response->failed() || $body === '' || $looksHtml || ! $isPdf) {
            // A 2xx whose body is an HTML page is the classic "this PDF host
            // needs a provider dashboard session" — distinct from a hard
            // transport failure, so the UI can word the fallback correctly.
            $status = ($looksHtml && ! $response->failed())
                ? FulfillmentDocumentStatus::ExternalUrlUnavailable
                : FulfillmentDocumentStatus::FetchFailed;

            return $this->recordUnfetchable($documentable, $type, $url, $status, $opts, [
                'http_status' => $response->status(),
                'content_type' => $contentType,
            ]);
        }

        return $this->writeBytes($documentable, $type, $body, FulfillmentDocumentStatus::Stored, $opts + ['source_url' => $url]);
    }

    /** Record that we only have an external URL we could not turn into a stored file. */
    public function recordUnfetchable(
        Model $documentable,
        FulfillmentDocumentType $type,
        string $url,
        FulfillmentDocumentStatus $status,
        array $opts = [],
        array $extraMetadata = [],
    ): FulfillmentDocument {
        return $this->upsert($documentable, $type, $opts, [
            'status' => $status->value,
            'path' => null,
            'mime_type' => null,
            'size_bytes' => null,
            'source_url' => $url,
        ], $extraMetadata);
    }

    // -----------------------------------------------------------------

    private function writeBytes(
        Model $documentable,
        FulfillmentDocumentType $type,
        string $bytes,
        FulfillmentDocumentStatus $status,
        array $opts,
    ): FulfillmentDocument {
        $disk = $this->disk();
        $directory = sprintf(
            'fulfillment/%s/%s/%s',
            $this->tenantSegment($documentable),
            $this->pathSegmentFor($documentable),
            $documentable->getKey(),
        );
        $storedName = (string) Str::uuid() . '.pdf';
        $path = $directory . '/' . $storedName;

        Storage::disk($disk)->put($path, $bytes);

        return $this->upsert($documentable, $type, $opts, [
            'status' => $status->value,
            'disk' => $disk,
            'path' => $path,
            'mime_type' => 'application/pdf',
            'size_bytes' => strlen($bytes),
            'source_url' => $opts['source_url'] ?? null,
        ]);
    }

    /**
     * One row per (documentable, document_type, provider_code) — a
     * regenerate REPLACES the prior row's fields rather than inserting a
     * second one. `provider_code` distinguishes e.g. the single-sheet
     * ticket PDF ('ozon') from the 4-up sheet ('ozon-4up').
     */
    private function upsert(
        Model $documentable,
        FulfillmentDocumentType $type,
        array $opts,
        array $overrides,
        array $extraMetadata = [],
    ): FulfillmentDocument {
        $providerCode = $opts['provider_code'] ?? null;
        $metadata = array_merge($opts['metadata'] ?? [], $extraMetadata);

        return FulfillmentDocument::updateOrCreate(
            [
                'documentable_type' => $documentable->getMorphClass(),
                'documentable_id' => $documentable->getKey(),
                'document_type' => $type->value,
                'provider_code' => $providerCode,
            ],
            array_merge([
                'store_id' => $documentable->getAttribute('store_id'),
                'organization_id' => $documentable->getAttribute('organization_id'),
                'disk' => $this->disk(),
                'path' => null,
                'source_url' => null,
                'mime_type' => null,
                'original_name' => $opts['original_name'] ?? null,
                'size_bytes' => null,
                'generated_by' => $opts['generated_by'] ?? null,
                'generated_at' => now(),
                'metadata' => $metadata === [] ? null : $metadata,
            ], $overrides),
        );
    }

    private function tenantSegment(Model $documentable): string
    {
        return (string) ($documentable->getAttribute('organization_id')
            ?? $documentable->getAttribute('store_id')
            ?? 'shared');
    }

    private function pathSegmentFor(Model $documentable): string
    {
        return match (true) {
            $documentable instanceof DeliveryNote => 'delivery-notes',
            $documentable instanceof Shipment => 'shipments',
            $documentable instanceof Order => 'orders',
            default => Str::snake(Str::pluralStudly(class_basename($documentable))),
        };
    }
}
