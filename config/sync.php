<?php

declare(strict_types=1);

return [
    /*
    |--------------------------------------------------------------------------
    | Initial order import range (days)
    |--------------------------------------------------------------------------
    |
    | A connection with no order-sync cursor yet (never synced before) imports
    | orders created/updated within this many days, not the platform's entire
    | history — importing "all orders forever" on a first sync is almost
    | never what a store actually wants, and can itself run long enough to
    | reproduce the same timeout this feature exists to fix.
    |
    | "Full order resync" (a separate, explicit user action) intentionally
    | bypasses this — it passes no cursor/range at all.
    */
    'orders_initial_import_days' => (int) env('ORDER_SYNC_INITIAL_DAYS', 30),
];
