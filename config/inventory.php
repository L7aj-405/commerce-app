<?php

declare(strict_types=1);

return [
    /*
    |--------------------------------------------------------------------------
    | Reserve stock for pending (unconfirmed) online orders
    |--------------------------------------------------------------------------
    |
    | Default false: an online order arriving from a platform sync is not
    | reserved until a human/WhatsApp confirms it — this matches most stores'
    | expectation that a "pending confirmation" order might still be declined
    | or never answered, and available stock should not drop for it yet.
    |
    | Set true to have OrderSyncService reserve stock (via
    | WarehouseAllocationService::allocate()) the moment a new pending order
    | is imported. Confirming the order later re-uses the SAME allocation
    | (WarehouseAllocationService is idempotent per order) rather than
    | reserving a second time.
    */
    'reserve_online_pending_orders' => env('INVENTORY_RESERVE_PENDING_ORDERS', false),
];
