<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\DeliveryConnection;
use App\Models\DeliveryProviderCity;
use Illuminate\Console\Command;

/**
 * Read-only diagnostic for a Sendit district sync — never calls Sendit
 * itself, only reports what is already stored locally. Use after a sync to
 * confirm the fix for the "only page 1 imported" bug: distinct city count
 * should be well above what a single ~100-row page could ever cover.
 */
class DiagnoseSenditDistrictsCommand extends Command
{
    protected $signature = 'delivery:diagnose-sendit-districts {connection_id : The Sendit DeliveryConnection ULID}';

    protected $description = 'Report district/city sync coverage for one Sendit connection (row counts, distinct cities, first 20 cities, last sync page count) without calling Sendit';

    public function handle(): int
    {
        $connectionId = (string) $this->argument('connection_id');

        $connection = DeliveryConnection::query()->find($connectionId);

        if ($connection === null) {
            $this->error("Delivery connection {$connectionId} not found.");

            return self::FAILURE;
        }

        if (! $connection->isSendit()) {
            $this->error("Connection {$connectionId} is provider '{$connection->provider_code}', not 'sendit'.");

            return self::FAILURE;
        }

        $rows = DeliveryProviderCity::query()
            ->where('store_id', $connection->store_id)
            ->where('provider_code', 'sendit')
            ->get(['city_name']);

        $totalRows = $rows->count();
        $distinctCities = $rows->pluck('city_name')->unique()->sort()->values();

        $this->info("Sendit connection: {$connection->name} ({$connection->id})");
        $this->line("Last district sync: " . ($connection->last_city_sync_at?->toDateTimeString() ?? 'never'));
        $this->line("Last sync page count: " . ($connection->last_city_sync_page_count ?? '—'));
        $this->line("Last sync pickup district used: " . ($connection->last_city_sync_pickup_district_id ?? '—'));
        $this->line("Total provider district rows: {$totalRows}");
        $this->line("Distinct provider city names: {$distinctCities->count()}");

        if ($distinctCities->isEmpty()) {
            $this->warn('No districts synced yet — run Sync districts first.');

            return self::SUCCESS;
        }

        $this->line('First 20 cities:');
        foreach ($distinctCities->take(20) as $city) {
            $this->line("  - {$city}");
        }

        return self::SUCCESS;
    }
}
