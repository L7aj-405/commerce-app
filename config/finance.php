<?php

declare(strict_types=1);

return [

    /*
    |--------------------------------------------------------------------------
    | Finance supporting documents
    |--------------------------------------------------------------------------
    |
    | Validation for uploaded expense/finance documents (invoices, receipts,
    | proofs of payment, scanned fuel/mazout receipts...). Private disk only
    | — never "public". See App\Services\Finance\FinanceDocumentService.
    |
    */
    'documents' => [
        'disk' => env('FINANCE_DOCUMENTS_DISK', 'local'),

        'max_size_kb' => (int) env('FINANCE_DOCUMENT_MAX_SIZE_KB', 10240), // 10 MB

        'allowed_mime_types' => [
            'application/pdf',
            'image/jpeg',
            'image/png',
            'image/webp',
        ],

        'allowed_extensions' => ['pdf', 'jpg', 'jpeg', 'png', 'webp'],
    ],

];
