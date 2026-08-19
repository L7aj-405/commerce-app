<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;
use App\Services\Sync\OrderSyncService;
use App\Models\PlatformConnection;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');



Schedule::call(function () {
    $connections = PlatformConnection::where('is_syncing', false)->get();
    $syncService = app(OrderSyncService::class);

    foreach ($connections as $connection) {
        // يمكنك مزامنة الطلبات منذ آخر تاريخ مزامنة أو آخر ساعة لتسريع العملية
        $since = $connection->last_synced_at ? $connection->last_synced_at->subMinutes(5) : null;
        
        try {
            $syncService->syncFromPlatform($connection->store, $connection, $since);
        } catch (\Throwable $e) {
            \Illuminate\Support\Facades\Log::error("Scheduled sync failed for store {$connection->store_id}: " . $e->getMessage());
        }
    }
})->everyMinute();