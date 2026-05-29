<?php

declare(strict_types=1);

namespace App\Contracts;

use Carbon\Carbon;

interface PlatformConnectorInterface
{
    public function testConnection(): bool;

    public function fetchProducts(int $page = 1, int $perPage = 50): array;

    public function fetchOrders(int $page = 1, int $perPage = 50, ?Carbon $since = null): array;

    public function fetchOrder(string $platformOrderId): ?array;

    public function updateOrderStatus(string $platformOrderId, string $status): bool;

    public function getPlatform(): string;

    public function getBaseUrl(): string;
}
