<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Foundation for per-organization / per-store customization of INTERNAL
 * fulfilment documents (pick/pack ticket, fallback label, SaaS pickup
 * manifest). Empty by default — DocumentTemplateResolver falls back to the
 * system defaults in config/documents.php when no row matches.
 *
 * No visual editor is built yet; a future editor just writes rows here.
 * Official provider PDFs (Ozon BL, Sendit labels) are never represented
 * here and never customizable.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('document_templates', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->ulid('organization_id');
            $table->ulid('store_id')->nullable(); // null => applies to the whole organization

            $table->string('document_type', 32); // App\Enums\FulfillmentDocumentType (customizable subset only)
            $table->string('name');
            $table->boolean('is_active')->default(true);

            // Partial override, deep-merged over config/documents.php defaults:
            // paper_format, orientation, margins, font, show_logo, header_text,
            // footer_text, language, barcode{type,position}, visible_fields[], labels{}.
            $table->json('settings')->nullable();

            // Reserved for a future custom Blade body / WYSIWYG output. Unused now.
            $table->longText('body')->nullable();

            $table->ulid('created_by')->nullable();
            $table->ulid('updated_by')->nullable();
            $table->timestamps();

            $table->foreign('organization_id')->references('id')->on('organizations')->cascadeOnDelete();
            $table->foreign('store_id')->references('id')->on('stores')->nullOnDelete();
            $table->foreign('created_by')->references('id')->on('users')->nullOnDelete();
            $table->foreign('updated_by')->references('id')->on('users')->nullOnDelete();

            // At most one active template per (org, store-scope, type). Short
            // explicit name — the auto one overflows MySQL's 64-char limit.
            $table->unique(['organization_id', 'store_id', 'document_type'], 'doc_templates_org_store_type_uq');
            $table->index(['organization_id', 'document_type', 'is_active'], 'doc_templates_lookup_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('document_templates');
    }
};
