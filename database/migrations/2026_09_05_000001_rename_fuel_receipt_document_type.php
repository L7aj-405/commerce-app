<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * FinanceDocumentType::FuelReceipt ('fuel_receipt') was renamed to FuelTicket
 * ('fuel_ticket') to match the business's naming ("fuel ticket") and to make
 * it explicit that a fuel ticket IS an official document type (part of
 * FinanceDocumentType::officialTypes()) — see the enum's docblock.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::table('finance_documents')->where('document_type', 'fuel_receipt')->update(['document_type' => 'fuel_ticket']);
    }

    public function down(): void
    {
        DB::table('finance_documents')->where('document_type', 'fuel_ticket')->update(['document_type' => 'fuel_receipt']);
    }
};
