<?php

declare(strict_types=1);

namespace App\Support;

use App\Models\Order;
use App\Models\PlatformConnection;
use App\Models\PosOrder;

/**
 * Phase OST — single source of truth for "where did this order come from",
 * used both to WRITE the normalized source columns when an order is created
 * (forConnection()/pos()) and to READ a UI-ready summary of them
 * (present()). Every place that needs source metadata should go through
 * this class rather than re-deriving platform labels/domains inline.
 *
 * Deliberately NOT analytics: this only describes where an order entered the
 * system (platform, connection, external id/number) — no attribution,
 * campaign, or traffic-source data of any kind. See OST7.
 */
final class OrderSourceSummary
{
    public const TYPE_POS = 'pos';
    public const TYPE_ONLINE = 'online';
    public const TYPE_MANUAL = 'manual';

    /**
     * Source metadata to persist on a new online Order row, derived from the
     * platform connection it was imported through. platform_connection_id +
     * external_order_id (platform_order_id) remain the authoritative online
     * order identity — this only adds descriptive/filterable metadata
     * alongside them, never replaces that identity check.
     *
     * @return array{source_type: string, source_platform: string, source_store_name: string, source_store_domain: ?string, source_channel_label: string}
     */
    public static function forConnection(PlatformConnection $connection): array
    {
        $platform = $connection->platform;
        $domain = self::domainFor($connection);
        $platformLabel = self::platformLabel($platform);
        $storeName = $connection->label ?: $platformLabel;

        return [
            'source_type' => self::TYPE_ONLINE,
            'source_platform' => $platform,
            'source_store_name' => $storeName,
            'source_store_domain' => $domain,
            'source_channel_label' => $domain !== null ? "{$platformLabel} - {$domain}" : $platformLabel,
        ];
    }

    /** Human label for a platform key — the one place this mapping lives. */
    public static function platformLabel(string $platform): string
    {
        return match ($platform) {
            PlatformConnection::PLATFORM_SHOPIFY => 'Shopify',
            PlatformConnection::PLATFORM_WOOCOMMERCE => 'WooCommerce',
            PlatformConnection::PLATFORM_YOUCAN => 'YouCan',
            self::TYPE_POS => 'POS',
            self::TYPE_MANUAL => 'Manual',
            default => ucfirst($platform),
        };
    }

    /** The domain/host that best identifies the connected store, per platform. */
    public static function domainFor(PlatformConnection $connection): ?string
    {
        return match ($connection->platform) {
            PlatformConnection::PLATFORM_SHOPIFY => self::nullableString($connection->shop_domain),
            PlatformConnection::PLATFORM_WOOCOMMERCE, PlatformConnection::PLATFORM_YOUCAN => self::hostFrom($connection->api_url),
            default => null,
        };
    }

    private static function hostFrom(?string $url): ?string
    {
        $url = self::nullableString($url);

        if ($url === null) {
            return null;
        }

        $host = parse_url($url, PHP_URL_HOST) ?: $url;

        return preg_replace('#^www\.#i', '', $host) ?: $host;
    }

    private static function nullableString(?string $value): ?string
    {
        $value = trim((string) $value);

        return $value === '' ? null : $value;
    }

    /**
     * UI-ready summary read from an already-populated Order/PosOrder row —
     * badge label, platform label, connection/store label, domain, and the
     * external order number where one exists. Never recomputes from the
     * live PlatformConnection — the stored columns are the source of truth
     * once an order is created, so this stays correct even if the
     * connection is later relabeled or disconnected.
     *
     * @return array{
     *     source_type: string, source_platform: string, platform_label: string,
     *     connection_label: ?string, store_domain: ?string,
     *     external_order_number: ?string, badge_label: string,
     * }
     */
    public static function present(Order|PosOrder $order): array
    {
        if ($order instanceof PosOrder) {
            return [
                'source_type' => self::TYPE_POS,
                'source_platform' => self::TYPE_POS,
                'platform_label' => 'POS',
                'connection_label' => $order->store?->name,
                'store_domain' => null,
                'external_order_number' => null,
                'badge_label' => 'POS',
            ];
        }

        $sourceType = $order->source_type ?? self::TYPE_ONLINE;
        $platform = $order->source_platform;
        $platformLabel = $platform !== null ? self::platformLabel($platform) : self::platformLabel($sourceType);

        return [
            'source_type' => $sourceType,
            'source_platform' => $platform ?? $sourceType,
            'platform_label' => $platformLabel,
            'connection_label' => $order->source_channel_label ?? $order->source_store_name,
            'store_domain' => $order->source_store_domain,
            'external_order_number' => $order->order_number,
            'badge_label' => $platformLabel,
        ];
    }
}
