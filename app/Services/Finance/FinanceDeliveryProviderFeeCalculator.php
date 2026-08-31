<?php

declare(strict_types=1);

namespace App\Services\Finance;

use App\Enums\FinanceDeliveryFeeSource;
use App\Models\DeliveryProvider;
use App\Models\DeliveryProviderCityFee;
use App\Models\DeliveryProviderFinanceSetting;
use App\Models\Order;
use App\Models\Shipment;
use App\Support\Delivery\CityNameNormalizer;
use Illuminate\Support\Carbon;
use Throwable;

/**
 * Resolves what an external delivery provider is expected to charge for one
 * order's shipment — used both to snapshot a Shipment's fee once (see
 * snapshotForShipment()) and, ad hoc, to preview a fee before that snapshot
 * exists. Priority, per the task spec:
 *
 *   1. The provider's OWN synced per-city pricing (DeliveryProviderCity —
 *      already populated by the existing Ozon "/cities" and Sendit
 *      "/districts" syncs; this IS the "API quote" tier, no live API call
 *      is ever made here).
 *   2. An organization-entered manual city fee override (DeliveryProviderCityFee).
 *   3. The provider's org-level default fee (DeliveryProviderFinanceSetting).
 *   4. Neither exists — flagged manual_required, never a fatal error.
 *
 * Pure/read-only: never writes anything itself (see snapshotForShipment()
 * for the one write path) and never throws — a lookup failure degrades to
 * manual_required rather than blocking the shipment/delivery workflow that
 * calls it.
 */
class FinanceDeliveryProviderFeeCalculator
{
    /**
     * @return array{delivery_fee:?float,return_fee:?float,refusal_fee:?float,cod_fee:?float,total_expected_fee:?float,source:string,metadata:array<string,mixed>}
     */
    public function calculateForOrder(Order $order, ?Shipment $shipment = null): array
    {
        $shipment ??= $order->shipment;
        $organization = $order->store?->organization;

        if ($organization === null || $shipment === null || $shipment->provider_code === null) {
            return $this->manualRequired('No external delivery provider shipment found for this order.');
        }

        $provider = DeliveryProvider::query()->where('code', $shipment->provider_code)->first();

        if ($provider === null) {
            return $this->manualRequired("Unknown delivery provider code '{$shipment->provider_code}'.");
        }

        $settings = DeliveryProviderFinanceSetting::query()
            ->where('organization_id', $organization->id)
            ->where('delivery_provider_id', $provider->id)
            ->first();

        $referenceDate = $shipment->delivered_at ?? $shipment->sent_at ?? Carbon::now();

        // Tier 1 — the provider's own synced pricing for the EXACT city this
        // shipment was routed to (Shipment.city_id -> DeliveryProviderCity).
        $providerCity = $shipment->city_id !== null ? $shipment->providerCity : null;
        $apiDeliveryFee = $providerCity?->delivered_price ?? $providerCity?->price;

        if ($providerCity !== null && $apiDeliveryFee !== null) {
            return $this->result(
                order: $order,
                settings: $settings,
                deliveryFee: (float) $apiDeliveryFee,
                returnFee: $providerCity->returned_price !== null ? (float) $providerCity->returned_price : 0.0,
                refusalFee: $providerCity->refused_price !== null ? (float) $providerCity->refused_price : 0.0,
                source: FinanceDeliveryFeeSource::ApiQuote,
                shipmentStatus: $shipment->status,
                metadata: ['provider_city_id' => $providerCity->id, 'city_name' => $shipment->city_name],
            );
        }

        // Tier 2 — a manual per-city override, matched by a real city
        // reference first, NEVER fragile spelling, when one is available —
        // see findCityFee().
        $cityFee = $this->findCityFee($organization->id, $provider->id, $shipment, $order, $referenceDate);

        if ($cityFee !== null) {
            return $this->result(
                order: $order,
                settings: $settings,
                deliveryFee: (float) $cityFee->delivery_fee,
                returnFee: (float) $cityFee->return_fee,
                refusalFee: (float) $cityFee->refusal_fee,
                source: FinanceDeliveryFeeSource::CityFee,
                shipmentStatus: $shipment->status,
                metadata: [
                    'city_fee_id' => $cityFee->id,
                    'city_name' => $cityFee->city_name ?? $shipment->city_name,
                    'matched_by' => $cityFee->provider_city_id !== null ? 'provider_city_id' : ($cityFee->city_id !== null ? 'city_id' : 'city_name'),
                ],
                codFeeFixed: (float) $cityFee->cod_fee_fixed,
                codFeePercent: (float) $cityFee->cod_fee_percent,
            );
        }

        // Tier 3 — the provider's org-level default.
        if ($settings !== null && $settings->default_delivery_fee !== null) {
            return $this->result(
                order: $order,
                settings: $settings,
                deliveryFee: (float) $settings->default_delivery_fee,
                returnFee: (float) $settings->default_return_fee,
                refusalFee: (float) $settings->default_refusal_fee,
                source: FinanceDeliveryFeeSource::ProviderDefault,
                shipmentStatus: $shipment->status,
                metadata: ['city_name' => $shipment->city_name],
            );
        }

        // Tier 4 — nothing configured.
        return $this->manualRequired(
            $shipment->city_name !== null
                ? "No city fee or default fee configured for {$provider->name} / {$shipment->city_name}."
                : "No default fee configured for {$provider->name}.",
        );
    }

    /**
     * Computes and stores the fee snapshot for a shipment ONCE — a no-op if
     * `fee_calculated_at` is already set, so calling this at both dispatch
     * time and delivery time (or repeatedly on retry) never overwrites an
     * existing snapshot. Never throws: a calculation failure is logged and
     * swallowed, since fee bookkeeping must never block the delivery
     * workflow that triggers it (see ShipmentTrackingService::apply()).
     */
    public function snapshotForShipment(Shipment $shipment): void
    {
        if ($shipment->hasFeeSnapshot()) {
            return;
        }

        try {
            $order = $shipment->shippable;

            if (! $order instanceof Order) {
                return; // fee snapshots are only meaningful for online Orders today
            }

            $result = $this->calculateForOrder($order, $shipment);

            $shipment->update([
                'expected_delivery_fee' => $result['delivery_fee'],
                'expected_return_fee' => $result['return_fee'],
                'expected_refusal_fee' => $result['refusal_fee'],
                'expected_cod_fee' => $result['cod_fee'],
                'expected_total_carrier_fee' => $result['total_expected_fee'],
                'fee_source' => $result['source'],
                'fee_calculated_at' => now(),
                'fee_metadata' => $result['metadata'],
            ]);
        } catch (Throwable $e) {
            report($e);
        }
    }

    /**
     * The single "make this Shipment usable by Finance settlement periods"
     * step — shared by every place an order can become genuinely delivered:
     * ShipmentTrackingService::apply() (real Ozon/Sendit tracking),
     * DispatchService::markDelivered() (the Delivery Board's own manual
     * "Mark delivered", which never touches the rich Shipment row on its
     * own — that's the actual bug this exists to close, an Instant/Daily
     * period silently having nothing to find), and the local-only
     * recalculate-settlement repair action. Idempotent (a Shipment already
     * marked Delivered with a fee snapshot is untouched) and safe to call
     * on every delivered transition without duplicating this logic at each
     * call site.
     *
     * Never throws — a Finance-side snag must never break a real delivery
     * confirmation. Never creates a FinanceTransaction and never closes a
     * receivable; it only ever writes Shipment.status/delivered_at and the
     * fee-snapshot columns.
     */
    public function prepareShipmentForSettlement(Shipment $shipment): void
    {
        try {
            if ($shipment->status !== Shipment::STATUS_DELIVERED || $shipment->delivered_at === null) {
                $shipment->update([
                    'status' => Shipment::STATUS_DELIVERED,
                    'delivered_at' => $shipment->delivered_at ?? now(),
                ]);
            }
        } catch (Throwable $e) {
            report($e);

            return;
        }

        $this->snapshotForShipment($shipment->fresh());
    }

    /**
     * Matching order, per the task spec — an id/code always wins over text:
     *   1. `provider_city_id` = Shipment.city_id — the EXACT provider city
     *      this shipment was routed to (same identifier Tier 1's API-quote
     *      lookup above already uses).
     *   2. `city_id` = Order.shipping_city_id — the canonical city the
     *      order was confirmed against, when the shipment itself has no
     *      provider-city match (e.g. Ozon/Sendit city sync incomplete).
     *   3. Legacy fallback: normalized `city_name` text match, for city fee
     *      rows created before this app had real city references at all.
     * Never falls back to (3) when (1) or (2) found nothing on an id-having
     * fee row — those rows already had their chance via the id lookups
     * above; (3) only exists so an OLD, id-less row keeps working.
     */
    private function findCityFee(string $organizationId, string $providerId, Shipment $shipment, Order $order, mixed $referenceDate): ?DeliveryProviderCityFee
    {
        $base = fn () => DeliveryProviderCityFee::query()
            ->where('organization_id', $organizationId)
            ->where('delivery_provider_id', $providerId)
            ->applicableOn($referenceDate);

        if ($shipment->city_id !== null) {
            $match = $base()->where('provider_city_id', $shipment->city_id)->first();

            if ($match !== null) {
                return $match;
            }
        }

        if ($order->shipping_city_id !== null) {
            $match = $base()->where('city_id', $order->shipping_city_id)->first();

            if ($match !== null) {
                return $match;
            }
        }

        if ($shipment->city_name !== null) {
            $normalized = CityNameNormalizer::normalize($shipment->city_name);

            return $base()->whereNull('provider_city_id')->whereNull('city_id')->whereNotNull('city_name')->get()
                ->first(fn (DeliveryProviderCityFee $fee) => CityNameNormalizer::normalize($fee->city_name) === $normalized);
        }

        return null;
    }

    /**
     * @return array{delivery_fee:?float,return_fee:?float,refusal_fee:?float,cod_fee:?float,total_expected_fee:?float,source:string,metadata:array<string,mixed>}
     */
    private function result(
        Order $order,
        ?DeliveryProviderFinanceSetting $settings,
        float $deliveryFee,
        float $returnFee,
        float $refusalFee,
        FinanceDeliveryFeeSource $source,
        string $shipmentStatus,
        array $metadata = [],
        ?float $codFeeFixed = null,
        ?float $codFeePercent = null,
    ): array {
        $codEnabled = $settings?->is_cod_enabled ?? true;
        $codFeeFixed ??= (float) ($settings?->cod_fee_fixed ?? 0);
        $codFeePercent ??= (float) ($settings?->cod_fee_percent ?? 0);

        $codFee = $codEnabled
            ? round($codFeeFixed + ($codFeePercent / 100) * (float) $order->total, 2)
            : 0.0;

        // The total only includes the fee legs that actually apply to this
        // shipment's real outcome — a successfully delivered parcel was
        // never returned or refused, so those contingent fees are recorded
        // for reference but not folded into the total that's actually owed.
        $total = match ($shipmentStatus) {
            Shipment::STATUS_RETURNED => round($deliveryFee + $returnFee, 2),
            Shipment::STATUS_REFUSED => round($deliveryFee + $refusalFee, 2),
            default => round($deliveryFee + $codFee, 2),
        };

        return [
            'delivery_fee' => round($deliveryFee, 2),
            'return_fee' => round($returnFee, 2),
            'refusal_fee' => round($refusalFee, 2),
            'cod_fee' => $codFee,
            'total_expected_fee' => $total,
            'source' => $source->value,
            'metadata' => $metadata,
        ];
    }

    /**
     * @return array{delivery_fee:?float,return_fee:?float,refusal_fee:?float,cod_fee:?float,total_expected_fee:?float,source:string,metadata:array<string,mixed>}
     */
    private function manualRequired(string $reason): array
    {
        return [
            'delivery_fee' => null,
            'return_fee' => null,
            'refusal_fee' => null,
            'cod_fee' => null,
            'total_expected_fee' => null,
            'source' => FinanceDeliveryFeeSource::ManualRequired->value,
            'metadata' => ['reason' => $reason],
        ];
    }
}
