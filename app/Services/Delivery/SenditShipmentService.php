<?php

declare(strict_types=1);

namespace App\Services\Delivery;

use App\Connectors\Delivery\SenditConnector;
use App\Enums\FulfillmentStatus;
use App\Models\DeliveryConnection;
use App\Models\Order;
use App\Models\Shipment;
use App\Models\User;
use App\Services\Finance\FinanceDeliveryProviderFeeCalculator;
use App\Services\Orders\DispatchService;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Sends a packed order to Sendit and records the result. Mirrors
 * OzonShipmentService's shape (validateReadiness + send, bridged into the
 * existing DispatchService::assign() so the order shows up on the Dispatch
 * board for free) but WITHOUT Ozon's extra post-create verification dance —
 * Sendit's docs give no indication add-parcel-style false positives are a
 * problem, so a delivery `code` in the response is trusted directly.
 */
class SenditShipmentService
{
    public function __construct(
        private readonly DispatchService $dispatch,
        private readonly DeliveryCityMappingResolver $cityResolver,
        private readonly FinanceDeliveryProviderFeeCalculator $feeCalculator,
    ) {}

    /** @throws ValidationException */
    public function validateReadiness(Order $order, DeliveryConnection $connection): void
    {
        if (! $connection->isSendit() || $connection->status !== DeliveryConnection::STATUS_CONNECTED) {
            throw ValidationException::withMessages(['connection' => 'This Sendit connection is not active.']);
        }

        $current = $order->fulfillment_status ?? FulfillmentStatus::Pending;

        if ($current !== FulfillmentStatus::ReadyForDelivery) {
            throw ValidationException::withMessages(['order' => 'Only orders ready for delivery can be sent to Sendit.']);
        }

        if (blank($order->customer_name) || blank($order->customer_phone)) {
            throw ValidationException::withMessages(['order' => 'The order needs a customer name and phone before it can be shipped.']);
        }

        if (blank($order->confirmed_shipping_address)) {
            throw ValidationException::withMessages(['order' => 'The order needs a confirmed shipping address before it can be shipped.']);
        }

        if (blank($order->total) || (float) $order->total <= 0) {
            throw ValidationException::withMessages(['order' => 'The order needs a positive amount before it can be shipped.']);
        }

        if (blank($order->order_number)) {
            throw ValidationException::withMessages(['order' => 'The order needs a reference before it can be shipped.']);
        }

        $pickupDistrictId = self::resolvePickupDistrictId($connection->settings ?? []);

        if (blank($pickupDistrictId)) {
            throw ValidationException::withMessages(['pickup_district' => 'Set a default pickup district for Sendit before sending orders.']);
        }

        $resolution = $this->cityResolver->resolve($order, $connection);

        if (! $resolution['resolved']) {
            throw ValidationException::withMessages(['city' => $resolution['error']]);
        }

        $existing = Shipment::query()
            ->where('shippable_type', Order::class)
            ->where('shippable_id', $order->getKey())
            ->where('provider_code', 'sendit')
            ->active()
            ->exists();

        if ($existing) {
            throw ValidationException::withMessages(['order' => 'This order already has an active Sendit shipment.']);
        }
    }

    /**
     * Non-throwing wrapper around validateReadiness() — for read-only "can
     * this be sent" UI checks (the Dispatch board's per-order readiness
     * display, the Dispatch modal's Integrated Provider tab). send() itself
     * still calls validateReadiness() directly; this never substitutes for
     * that real check at send time, only mirrors it for display.
     *
     * @return array{ready: bool, reasons: array<int, string>}
     */
    public function checkReadiness(Order $order, DeliveryConnection $connection): array
    {
        try {
            $this->validateReadiness($order, $connection);

            return ['ready' => true, 'reasons' => []];
        } catch (ValidationException $e) {
            return ['ready' => false, 'reasons' => collect($e->errors())->flatten()->values()->all()];
        }
    }

    public static function resolvePickupDistrictId(array $settings): ?string
    {
        $value = $settings['default_pickup_district_id'] ?? null;

        return $value === null || $value === '' ? null : (string) $value;
    }

    private static function resolveFlag(array $settings, string $key, string $default): string
    {
        if (! array_key_exists($key, $settings) || $settings[$key] === null || $settings[$key] === '') {
            return $default;
        }

        $value = $settings[$key];

        if (is_bool($value)) {
            return $value ? '1' : '0';
        }

        return (string) $value === '1' || (string) $value === 'true' ? '1' : '0';
    }

    /**
     * @param  array<string, mixed>  $options  optional overrides: amount, comment
     *
     * @throws ValidationException readiness/city-mapping/pickup-district problems (the order/setup isn't ready)
     * @throws SenditShipmentCreationException Sendit rejected the delivery, or its response couldn't be parsed
     */
    public function send(Order $order, DeliveryConnection $connection, array $options, User $actor): Shipment
    {
        $this->validateReadiness($order, $connection);

        $resolution = $this->cityResolver->resolve($order, $connection);

        if (! $resolution['resolved']) {
            throw ValidationException::withMessages(['city' => $resolution['error']]);
        }

        $amount = isset($options['amount']) ? (float) $options['amount'] : (float) $order->total;
        $settings = $connection->settings ?? [];
        $pickupDistrictId = self::resolvePickupDistrictId($settings);

        $connector = new SenditConnector($connection);

        $result = $connector->createShipment($order, $connection, [
            'pickup_district_id' => $pickupDistrictId,
            'district_id' => $resolution['provider_city_id'],
            'name' => $order->customer_name,
            'phone' => $order->customer_phone,
            'address' => $order->confirmed_shipping_address,
            'amount' => $amount,
            'comment' => $options['comment'] ?? ($settings['default_comment'] ?? null),
            'reference' => (string) ($order->order_number ?? $order->id),
            'allow_open' => self::resolveFlag($settings, 'allow_open', '0'),
            'allow_try' => self::resolveFlag($settings, 'allow_try', '0'),
            // Phase 1 recommendation: never send real stock/product data —
            // out of scope (see class docblock and the ticket's own "Do NOT
            // implement" list: Sendit stock movements/products inventory mode).
            'products_from_stock' => 0,
            'packaging_id' => $settings['packaging_id'] ?? null,
            'option_exchange' => self::resolveFlag($settings, 'option_exchange', '0'),
        ]);

        if (! $result['ok']) {
            throw new SenditShipmentCreationException(
                $result['error'] ?? 'Sendit did not accept this delivery.',
                $result['debug'] ?? [],
            );
        }

        $shipment = DB::transaction(function () use ($order, $connection, $resolution, $amount, $result, $actor) {
            $shipment = Shipment::updateOrCreate(
                [
                    'store_id' => $order->store_id,
                    'shippable_type' => Order::class,
                    'shippable_id' => $order->getKey(),
                    'provider_code' => 'sendit',
                ],
                [
                    'organization_id' => $order->organization_id,
                    'delivery_connection_id' => $connection->id,
                    'provider_shipment_id' => $result['provider_shipment_id'] ?? null,
                    'tracking_number' => $result['tracking_number'],
                    'status' => Shipment::STATUS_SENT_TO_CARRIER,
                    'provider_status' => null,
                    'receiver_name' => $order->customer_name,
                    'phone' => $order->customer_phone,
                    'city_id' => $resolution['provider_city_record_id'],
                    'city_name' => $resolution['provider_city_name'],
                    'address' => $order->confirmed_shipping_address,
                    'cod_amount' => $amount,
                    'raw_payload' => ['create' => $result['raw']],
                    'sent_at' => now(),
                ],
            );

            $orderShipment = $this->dispatch->assign($order, [
                'carrier_type' => 'courier',
                'carrier_name' => 'Sendit',
                'tracking_number' => $shipment->tracking_number,
                'notes' => "Sendit shipment {$shipment->tracking_number}",
            ], $actor);

            $shipment->update(['order_shipment_id' => $orderShipment->id]);

            return $shipment->refresh();
        });

        // Freeze the carrier fee snapshot at hand-off — same shared,
        // idempotent, never-throwing path Ozon uses (provider-district
        // price / city override / provider default / manual ladder). Never
        // reads a fee from the Sendit create response.
        $this->feeCalculator->snapshotForShipment($shipment);

        return $shipment->refresh();
    }
}
