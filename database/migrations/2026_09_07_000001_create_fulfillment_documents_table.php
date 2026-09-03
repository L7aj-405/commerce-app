<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Generic private store for fulfilment paperwork — Ozon Bon de Livraison
 * PDFs, per-parcel carrier label tickets, SaaS-generated fallback labels,
 * and (later) pick tickets / pickup manifests. Deliberately its OWN table,
 * not finance_documents (which is intentionally finance-evidence only): a
 * fulfilment document is never read by the ledger and never writes a
 * finance_transaction.
 *
 * Store-scoped (BelongsToTenant), mirroring shipments / delivery_notes so
 * route-model binding tenant-scopes it the same way. `organization_id` is a
 * denormalised copy only, never scoped on.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('fulfillment_documents', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->ulid('store_id');
            $table->ulid('organization_id')->nullable(); // denormalised only, not scoped on

            $table->ulidMorphs('documentable'); // Shipment | DeliveryNote | Order

            $table->string('document_type', 32);              // App\Enums\FulfillmentDocumentType
            $table->string('status', 32)->default('generated'); // App\Enums\FulfillmentDocumentStatus
            $table->string('provider_code')->nullable();

            $table->string('disk', 40)->default('local');
            $table->string('path')->nullable();          // null when only an unfetchable external URL is known
            $table->string('source_url', 1000)->nullable();
            $table->string('mime_type', 100)->nullable();
            $table->string('original_name')->nullable();
            $table->unsignedBigInteger('size_bytes')->nullable();

            $table->ulid('generated_by')->nullable();
            $table->timestamp('generated_at')->nullable();
            $table->json('metadata')->nullable();

            $table->timestamps();

            $table->foreign('store_id')->references('id')->on('stores')->cascadeOnDelete();
            $table->foreign('generated_by')->references('id')->on('users')->nullOnDelete();

            // Explicit short names — the auto-generated ones overflow MySQL's
            // 64-char identifier limit on this table/column combination.
            $table->index(['store_id', 'documentable_type', 'documentable_id'], 'fulfil_docs_store_documentable_idx');
            $table->index(['documentable_type', 'documentable_id', 'document_type'], 'fulfil_docs_documentable_type_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('fulfillment_documents');
    }
};
