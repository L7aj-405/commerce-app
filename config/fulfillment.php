<?php

declare(strict_types=1);

return [

    /*
    |--------------------------------------------------------------------------
    | Fulfilment documents
    |--------------------------------------------------------------------------
    |
    | Private storage for carrier labels, Bon de Livraison PDFs and
    | SaaS-generated fallback labels. Private disk only — never "public";
    | every download goes through the authorized
    | App\Http\Controllers\Dashboard\FulfillmentDocumentController route.
    |
    */
    'documents' => [
        'disk' => env('FULFILLMENT_DOCUMENTS_DISK', 'local'),
    ],

    /*
    | Server-side fetch of a provider's label/BL PDF URL. A short budget: if
    | the provider's PDF host needs a dashboard session the request just
    | returns an HTML page quickly, and we fall back to an internal label.
    */
    'fetch' => [
        'connect_timeout' => (int) env('FULFILLMENT_FETCH_CONNECT_TIMEOUT', 10),
        'timeout' => (int) env('FULFILLMENT_FETCH_TIMEOUT', 30),
    ],

];
