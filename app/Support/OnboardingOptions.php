<?php

declare(strict_types=1);

namespace App\Support;

/**
 * Static option lists shared by every onboarding controller/page — kept in
 * one place so the merchant and agency flows never drift apart on the same
 * dropdown. Countries/platforms were previously duplicated inline inside
 * OnboardingController; this is the single source now.
 */
final class OnboardingOptions
{
    /**
     * @deprecated Despite the name, this is the online/physical/hybrid
     * `Store.type` list, not an industry/category — the onboarding
     * controllers already used it correctly for that, just under a
     * misleading constant name. Use STORE_TYPES for any new code; this
     * alias is kept so the existing onboarding controllers/pages don't need
     * touching just for a rename.
     */
    public const BUSINESS_TYPES = self::STORE_TYPES;

    /** `Store.type`: online / physical / hybrid. */
    public const STORE_TYPES = [
        ['value' => 'online',   'label' => 'Online'],
        ['value' => 'physical', 'label' => 'Physical'],
        ['value' => 'hybrid',   'label' => 'Hybrid'],
    ];

    /** `Store.business_type`: industry/category — optional, unrelated to STORE_TYPES. */
    public const INDUSTRIES = [
        ['value' => 'retail',      'label' => 'Retail Store'],
        ['value' => 'restaurant',  'label' => 'Restaurant / Café'],
        ['value' => 'fashion',     'label' => 'Clothing & Fashion'],
        ['value' => 'electronics', 'label' => 'Electronics'],
        ['value' => 'grocery',     'label' => 'Grocery'],
        ['value' => 'other',       'label' => 'Other'],
    ];

    public const COUNTRIES = [
        ['code' => 'US', 'name' => 'United States',   'currency' => 'USD'],
        ['code' => 'CA', 'name' => 'Canada',          'currency' => 'CAD'],
        ['code' => 'GB', 'name' => 'United Kingdom',  'currency' => 'GBP'],
        ['code' => 'FR', 'name' => 'France',          'currency' => 'EUR'],
        ['code' => 'DE', 'name' => 'Germany',         'currency' => 'EUR'],
        ['code' => 'ES', 'name' => 'Spain',           'currency' => 'EUR'],
        ['code' => 'IT', 'name' => 'Italy',           'currency' => 'EUR'],
        ['code' => 'MA', 'name' => 'Morocco',         'currency' => 'MAD'],
        ['code' => 'DZ', 'name' => 'Algeria',         'currency' => 'DZD'],
        ['code' => 'TN', 'name' => 'Tunisia',         'currency' => 'TND'],
        ['code' => 'EG', 'name' => 'Egypt',           'currency' => 'EGP'],
        ['code' => 'SA', 'name' => 'Saudi Arabia',    'currency' => 'SAR'],
        ['code' => 'AE', 'name' => 'UAE',             'currency' => 'AED'],
        ['code' => 'AU', 'name' => 'Australia',       'currency' => 'AUD'],
        ['code' => 'JP', 'name' => 'Japan',           'currency' => 'JPY'],
        ['code' => 'IN', 'name' => 'India',           'currency' => 'INR'],
        ['code' => 'BR', 'name' => 'Brazil',          'currency' => 'BRL'],
        ['code' => 'MX', 'name' => 'Mexico',          'currency' => 'MXN'],
    ];

    public const PLATFORMS = [
        ['value' => 'pos',         'label' => 'POS (in-store)'],
        ['value' => 'woocommerce', 'label' => 'WooCommerce'],
        ['value' => 'shopify',     'label' => 'Shopify'],
        ['value' => 'youcan',      'label' => 'YouCan Shop'],
        ['value' => 'manual',      'label' => 'Manual orders'],
    ];

    public const INVENTORY_SOURCES = [
        ['value' => 'csv',         'label' => 'Import from Excel/CSV'],
        ['value' => 'woocommerce', 'label' => 'Import from WooCommerce'],
        ['value' => 'shopify',     'label' => 'Import from Shopify'],
        ['value' => 'youcan',      'label' => 'Import from YouCan'],
        ['value' => 'empty',       'label' => 'Start empty'],
    ];

    public const AGENCY_SERVICES = [
        ['value' => 'confirmation',      'label' => 'Confirmation'],
        ['value' => 'customer_support',  'label' => 'Customer support'],
        ['value' => 'delivery',          'label' => 'Delivery'],
        ['value' => 'warehousing',       'label' => 'Warehousing / Fulfillment'],
    ];

    public const ACCOUNT_MODES = [
        ['value' => 'merchant', 'label' => 'I manage my own business'],
        ['value' => 'agency',   'label' => 'I manage businesses for clients'],
    ];

    public static function businessTypeValues(): array
    {
        return array_column(self::BUSINESS_TYPES, 'value');
    }

    public static function storeTypeValues(): array
    {
        return array_column(self::STORE_TYPES, 'value');
    }

    public static function industryValues(): array
    {
        return array_column(self::INDUSTRIES, 'value');
    }

    public static function platformValues(): array
    {
        return array_column(self::PLATFORMS, 'value');
    }

    public static function inventorySourceValues(): array
    {
        return array_column(self::INVENTORY_SOURCES, 'value');
    }
}
