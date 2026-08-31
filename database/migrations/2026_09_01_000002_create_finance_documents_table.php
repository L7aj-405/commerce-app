<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Generic supporting-document store for Finance records (expenses
        // today; COD settlements/courier deposits/vendor documents later —
        // see the `documentable_*` polymorphic pair). Purely additive: never
        // referenced by finance_transactions, so uploading/deleting a
        // document can never affect the ledger.
        Schema::create('finance_documents', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->ulid('organization_id');
            $table->ulid('store_id')->nullable(); // nullable: mirrors the parent record's own store scope

            $table->ulidMorphs('documentable'); // documentable_type, documentable_id

            $table->string('original_name'); // client filename — never trusted for storage, display only
            $table->string('stored_name'); // generated safe filename actually written to disk
            $table->string('disk', 40)->default('local');
            $table->string('path'); // path within the disk — never exposed to the client directly
            $table->string('mime_type', 100);
            $table->string('extension', 10);
            $table->unsignedBigInteger('size_bytes');

            $table->string('document_type', 30)->nullable(); // invoice | receipt | payment_proof | fuel_receipt | supplier_invoice | other
            $table->string('description')->nullable();

            $table->ulid('uploaded_by')->nullable();
            $table->ulid('deleted_by')->nullable();

            $table->timestamps();
            $table->softDeletes();

            $table->foreign('organization_id')->references('id')->on('organizations')->cascadeOnDelete();
            $table->foreign('store_id')->references('id')->on('stores')->nullOnDelete();
            $table->foreign('uploaded_by')->references('id')->on('users')->nullOnDelete();
            $table->foreign('deleted_by')->references('id')->on('users')->nullOnDelete();

            $table->index(['organization_id', 'documentable_type', 'documentable_id'], 'finance_documents_org_documentable_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('finance_documents');
    }
};
