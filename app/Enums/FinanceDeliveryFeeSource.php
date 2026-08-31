<?php

declare(strict_types=1);

namespace App\Enums;

/** Where a Shipment's fee snapshot came from — see FinanceDeliveryProviderFeeCalculator. */
enum FinanceDeliveryFeeSource: string
{
    /** The provider's OWN synced per-city pricing (DeliveryProviderCity.delivered_price/returned_price/refused_price/price). */
    case ApiQuote = 'api_quote';
    /** An organization-entered manual city fee override (DeliveryProviderCityFee). */
    case CityFee = 'city_fee';
    /** The provider's org-level default fee (DeliveryProviderFinanceSetting). */
    case ProviderDefault = 'provider_default';
    /** Neither a city fee nor a provider default exists — needs manual review. */
    case ManualRequired = 'manual_required';

    public function label(): string
    {
        return match ($this) {
            self::ApiQuote => 'Provider API quote',
            self::CityFee => 'City fee override',
            self::ProviderDefault => 'Provider default fee',
            self::ManualRequired => 'Manual review required',
        };
    }
}
