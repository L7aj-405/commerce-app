<?php

declare(strict_types=1);

namespace App\Support;

use App\Models\City;
use App\Models\Order;

/**
 * The customer's ORIGINAL shipping/delivery address, as the platform sent
 * it — read-only reference data for the Confirmation Desk, never the
 * editable `confirmed_shipping_address`/`shipping_city_id` the agent may
 * change. Each platform stores this differently in `platform_data` (the raw
 * payload captured at import); this is the one place that knowledge lives.
 *
 * Root cause this exists to fix: WooCommerceConnector::parseOrder() never
 * preserved the raw order at all (only a tiny `metadata` blob), so the
 * billing/shipping sub-arrays never survived past import — the Confirmation
 * Desk's address field was empty for every WooCommerce order regardless of
 * what the customer actually entered. Shopify's raw payload WAS preserved,
 * but the old fallback looked at the wrong keys (`shipping.address_1`
 * instead of Shopify's real `shipping_address.address1`).
 */
final class OrderAddressSummary
{
    /**
     * @return array{
     *     name: ?string, phone: ?string, address1: ?string, address2: ?string,
     *     city: ?string, province: ?string, country: ?string, notes: ?string,
     *     has_address: bool,
     * }
     */
    public static function extract(Order $order): array
    {
        $data = is_array($order->platform_data) ? $order->platform_data : [];

        $fields = match ($order->source_platform) {
            'shopify' => self::fromShopify($data),
            'woocommerce' => self::fromWooCommerce($data),
            'youcan' => self::fromYouCan($data),
            default => self::blank(),
        };

        $fields['has_address'] = filled($fields['address1']) || filled($fields['city']);

        return $fields;
    }

    /**
     * Match the platform's raw city text against the known city list —
     * exact (case/accent-insensitive) name match only. Returns null when
     * there's nothing to match or no confident match exists; the caller
     * must never guess further than this, and the agent must pick
     * explicitly when it's null.
     */
    public static function matchCity(?string $rawCity, string $countryCode = 'MA'): ?City
    {
        $rawCity = self::blankToNull($rawCity);

        if ($rawCity === null) {
            return null;
        }

        return City::query()
            ->where('country_code', $countryCode)
            ->where('is_active', true)
            ->whereRaw('LOWER(name) = ?', [mb_strtolower($rawCity)])
            ->first();
    }

    /** @return array{name: ?string, phone: ?string, address1: ?string, address2: ?string, city: ?string, province: ?string, country: ?string, notes: ?string} */
    private static function fromShopify(array $data): array
    {
        $shipping = $data['shipping_address'] ?? [];
        if ($shipping === [] || $shipping === null) {
            $shipping = $data['billing_address'] ?? [];
        }

        $name = trim(($shipping['first_name'] ?? '') . ' ' . ($shipping['last_name'] ?? ''));

        return [
            'name' => self::blankToNull($name) ?? self::blankToNull($shipping['name'] ?? null),
            'phone' => self::blankToNull($shipping['phone'] ?? null),
            'address1' => self::blankToNull($shipping['address1'] ?? null),
            'address2' => self::blankToNull($shipping['address2'] ?? null),
            'city' => self::blankToNull($shipping['city'] ?? null),
            'province' => self::blankToNull($shipping['province'] ?? null),
            'country' => self::blankToNull($shipping['country'] ?? null),
            'notes' => self::blankToNull($data['note'] ?? null),
        ];
    }

    /** @return array{name: ?string, phone: ?string, address1: ?string, address2: ?string, city: ?string, province: ?string, country: ?string, notes: ?string} */
    private static function fromWooCommerce(array $data): array
    {
        $shipping = $data['shipping'] ?? [];
        $billing = $data['billing'] ?? [];

        // Many WooCommerce stores never collect a separate shipping address
        // (ship-to-billing) — fall back to billing only when shipping has no
        // street address at all, never partially mix the two.
        if (self::blankToNull($shipping['address_1'] ?? null) === null) {
            $shipping = $billing;
        }

        $name = trim(($shipping['first_name'] ?? '') . ' ' . ($shipping['last_name'] ?? ''));

        return [
            'name' => self::blankToNull($name),
            'phone' => self::blankToNull($shipping['phone'] ?? null) ?? self::blankToNull($billing['phone'] ?? null),
            'address1' => self::blankToNull($shipping['address_1'] ?? null),
            'address2' => self::blankToNull($shipping['address_2'] ?? null),
            'city' => self::blankToNull($shipping['city'] ?? null),
            'province' => self::blankToNull($shipping['state'] ?? null),
            'country' => self::blankToNull($shipping['country'] ?? null),
            'notes' => self::blankToNull($data['customer_note'] ?? null),
        ];
    }

    /**
     * YouCan's raw order shape isn't documented anywhere in this codebase —
     * checked defensively against the handful of plausible keys rather than
     * inventing a structure. If none match, every field is honestly null and
     * has_address is false; the Confirmation Desk then shows the "no address
     * provided" warning instead of a wrong guess.
     *
     * @return array{name: ?string, phone: ?string, address1: ?string, address2: ?string, city: ?string, province: ?string, country: ?string, notes: ?string}
     */
    private static function fromYouCan(array $data): array
    {
        $shipping = $data['shipping_address'] ?? $data['address'] ?? $data['customer']['address'] ?? [];

        if (! is_array($shipping)) {
            $shipping = [];
        }

        $customer = $data['customer'] ?? [];
        $name = trim(($shipping['name'] ?? '') ?: (($customer['fullname'] ?? '') ?: (($customer['first_name'] ?? '') . ' ' . ($customer['last_name'] ?? ''))));

        return [
            'name' => self::blankToNull($name),
            'phone' => self::blankToNull($shipping['phone'] ?? $customer['phone'] ?? null),
            'address1' => self::blankToNull($shipping['address1'] ?? $shipping['address'] ?? $shipping['address_1'] ?? null),
            'address2' => self::blankToNull($shipping['address2'] ?? $shipping['address_2'] ?? null),
            'city' => self::blankToNull($shipping['city'] ?? null),
            'province' => self::blankToNull($shipping['province'] ?? $shipping['state'] ?? null),
            'country' => self::blankToNull($shipping['country'] ?? null),
            'notes' => self::blankToNull($data['note'] ?? $data['notes'] ?? null),
        ];
    }

    /** @return array{name: null, phone: null, address1: null, address2: null, city: null, province: null, country: null, notes: null} */
    private static function blank(): array
    {
        return array_fill_keys(['name', 'phone', 'address1', 'address2', 'city', 'province', 'country', 'notes'], null);
    }

    private static function blankToNull(mixed $value): ?string
    {
        if (! is_string($value)) {
            return null;
        }

        $value = trim($value);

        return $value === '' ? null : $value;
    }
}
