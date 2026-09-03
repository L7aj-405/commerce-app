<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| Document template foundation
|--------------------------------------------------------------------------
|
| System-default templates for INTERNAL / fallback fulfilment documents.
| These are the baseline the DocumentTemplateResolver returns when no
| store/organization custom template row exists in `document_templates`.
|
| Official provider PDFs (Ozon Bon de Livraison, Sendit labels) are NOT
| listed here and are never routed through this abstraction — they stay
| exactly as the provider returns them.
|
| A custom `document_templates` row supplies a PARTIAL `settings` override
| that is deep-merged over the matching entry below; the Blade view stays
| the system one unless a future editor sets a custom body.
|
*/

return [

    'templates' => [

        // Internal pick / pack ticket — used by the warehouse team to
        // assemble and verify a parcel before it is handed to a carrier.
        'pick_ticket' => [
            'view' => 'documents.pick-pack-ticket',
            'settings' => [
                'paper_format'  => 'A5',              // 'A4' | 'A5' | 'A6' | [width, height] in mm
                'orientation'   => 'P',              // 'P' | 'L'
                'margins'       => ['top' => 10, 'right' => 10, 'bottom' => 10, 'left' => 10],
                'font'          => 'dejavusans',
                'show_logo'     => true,
                'header_text'   => null,              // null => store / organization name
                'footer_text'   => 'Internal document — this is NOT a carrier label.',
                'language'      => 'en',
                'barcode'       => ['type' => 'C128B', 'position' => 'header'], // position: 'header' | 'footer' | 'none'
                // Optional sections/columns the template renders when present.
                'visible_fields' => [
                    'warehouse', 'customer', 'phone', 'city', 'address', 'notes',
                    'payment', 'cod_amount', 'sku', 'barcode', 'checklist', 'signatures',
                ],
                'labels' => [
                    'title'         => 'Pick / Pack Ticket',
                    'order'         => 'Order',
                    'internal_id'   => 'Internal ID',
                    'order_date'    => 'Order date',
                    'printed'       => 'Printed',
                    'customer'      => 'Customer',
                    'phone'         => 'Phone',
                    'city'          => 'City',
                    'address'       => 'Address',
                    'notes'         => 'Delivery notes',
                    'payment'       => 'Payment',
                    'cod_amount'    => 'COD to collect',
                    'prepaid'       => 'PREPAID',
                    'items'         => 'Items to pick',
                    'product'       => 'Product',
                    'variant'       => 'Variant',
                    'sku'           => 'SKU',
                    'qty'           => 'Qty',
                    'pick'          => 'Pick',
                    'pack'          => 'Pack',
                    'checklist'     => 'Operational checklist',
                    'signatures'    => 'Sign-off',
                    'picker'        => 'Picker',
                    'packer'        => 'Packer',
                    'dispatcher'    => 'Dispatcher',
                ],
            ],
        ],

    ],

];
