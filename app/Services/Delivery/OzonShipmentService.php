<?php

declare(strict_types=1);

namespace App\Services\Delivery;

use App\Connectors\Delivery\OzonExpressConnector;
use App\Enums\FulfillmentStatus;
use App\Models\DeliveryConnection;
use App\Models\Order;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\Shipment;
use App\Models\User;
use App\Services\Finance\FinanceDeliveryProviderFeeCalculator;
use App\Services\Orders\DispatchService;
use App\Support\OrderLineItems;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Sends a packed order to Ozon Express and records the result.
 *
 * Deliberately reuses the existing DispatchService::assign() (unmodified) so
 * an Ozon shipment also becomes a real order_shipments row — the order shows
 * up on the existing Dispatch board with zero changes to that board's own
 * assignment/tracking logic.
 */
class OzonShipmentService
{
    public function __construct(
        private readonly DispatchService $dispatch,
        private readonly DeliveryCityMappingResolver $cityResolver,
        private readonly FinanceDeliveryProviderFeeCalculator $feeCalculator,
    ) {}

    /** @throws ValidationException */
    public function validateReadiness(Order $order, DeliveryConnection $connection): void
    {
        if (! $connection->isOzon() || $connection->status !== DeliveryConnection::STATUS_CONNECTED) {
            throw ValidationException::withMessages(['connection' => 'This Ozon connection is not active.']);
        }

        $current = $order->fulfillment_status ?? FulfillmentStatus::Pending;

        if ($current !== FulfillmentStatus::ReadyForDelivery) {
            throw ValidationException::withMessages(['order' => 'Only orders ready for delivery can be sent to Ozon.']);
        }

        if (blank($order->customer_name) || blank($order->customer_phone)) {
            throw ValidationException::withMessages(['order' => 'The order needs a customer name and phone before it can be shipped.']);
        }

        if (blank($order->confirmed_shipping_address)) {
            throw ValidationException::withMessages(['order' => 'The order needs a confirmed shipping address before it can be shipped.']);
        }

        $resolution = $this->cityResolver->resolve($order, $connection);

        if (! $resolution['resolved']) {
            throw ValidationException::withMessages(['city' => $resolution['error']]);
        }

        // A "stock parcel" (parcel-stock=1) is REQUIRED by Ozon to carry
        // product details — block locally with a precise message rather
        // than letting Ozon reject it with its own generic error.
        $stock = self::resolveParcelStock($connection->settings ?? []);

        if ($stock === '1' && $this->buildProductsPayload($order) === []) {
            throw ValidationException::withMessages([
                'products' => 'Ozon stock parcels require product details.',
            ]);
        }

        $existing = Shipment::query()
            ->where('shippable_type', Order::class)
            ->where('shippable_id', $order->getKey())
            ->where('provider_code', 'ozon')
            ->active()
            ->exists();

        if ($existing) {
            throw ValidationException::withMessages(['order' => 'This order already has an active Ozon shipment.']);
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

    /**
     * The connection's `default_parcel_stock` setting, exactly as saved —
     * "0" must stay "0". `??`/`?:`/`empty()` all treat "0" as falsy/absent
     * and would silently substitute a different value; `array_key_exists`
     * is the only correct way to distinguish "explicitly configured" from
     * "never configured". An explicitly-empty string is treated the same as
     * unconfigured (Ozon's own default, and this project's policy default)
     * — parcel-stock must never be sent as an empty string.
     */
    public static function resolveParcelStock(array $settings): string
    {
        if (! array_key_exists('default_parcel_stock', $settings)) {
            return '0';
        }

        $value = (string) $settings['default_parcel_stock'];

        return $value === '' ? '0' : $value;
    }

    /**
     * Ozon's parcel-open is NOT a boolean — it's "1" (ouvrir le colis) or
     * "2" (ne pas ouvrir), default "1" per Ozon's own docs. Any other/blank
     * value falls back to the documented default rather than being sent
     * as-is (Ozon has no third state to interpret it as).
     */
    public static function resolveParcelOpen(array $settings): string
    {
        $value = (string) ($settings['default_parcel_open'] ?? '1');

        return in_array($value, ['1', '2'], true) ? $value : '1';
    }

    /**
     * fragile/replace are real Ozon booleans ("1"/"0", both documented
     * default "0") — the UI keeps them as checkboxes, but whatever type
     * lands in settings (PHP bool from a fresh save, or a legacy "1"/"0"
     * string) is normalized to the exact string Ozon expects.
     */
    public static function resolveBooleanFlag(array $settings, string $key): string
    {
        if (! array_key_exists($key, $settings) || $settings[$key] === null || $settings[$key] === '') {
            return '0';
        }

        $value = $settings[$key];

        if (is_bool($value)) {
            return $value ? '1' : '0';
        }

        return (string) $value === '1' || (string) $value === 'true' ? '1' : '0';
    }

    /**
     * @return array<int, array{ref: string, qty: int}>
     */
    private function buildProductsPayload(Order $order): array
    {
        $products = [];

        foreach (OrderLineItems::for($order) as $line) {
            $ref = $this->resolveProductRef($line);

            if ($ref === null) {
                continue;
            }

            // Ozon's documented products field name is "qnty", NOT "qty" —
            // sending "qty" is silently ignored by Ozon, which is why a
            // stock parcel (parcel-stock=1) kept failing with "Products data
            // required for stock parcels" even when a correctly-shaped
            // products array (ref + a quantity key) was already being sent.
            $products[] = ['ref' => $ref, 'qnty' => max(1, (int) $line['quantity'])];
        }

        return $products;
    }

    /**
     * Ozon's "ref" needs a real, stable identifier — never a human-readable
     * product name (a localized display string is not a reference). Falls
     * through, in order: the line's own SKU (as the platform reported it,
     * already normalized by OrderLineItems) -> the LOCAL variant's own SKU
     * -> the LOCAL product's own SKU (covers a line the platform sent with
     * no sku field at all, but that OrderLineItems still matched to a real
     * local product/variant via an external id) -> the platform's own
     * product/variant identifier -> the local product/variant ULID. A line
     * with none of these (a genuinely custom/service line) is skipped
     * entirely, never sent with a fabricated or name-based ref.
     *
     * @param  array<string, mixed>  $line  one row from OrderLineItems::for()
     */
    private function resolveProductRef(array $line): ?string
    {
        if (filled($line['sku'])) {
            return (string) $line['sku'];
        }

        if ($line['variant_id'] !== null) {
            $variantSku = ProductVariant::withoutTenancy(
                fn () => ProductVariant::query()->find($line['variant_id'])
            )?->sku;

            if (filled($variantSku)) {
                return (string) $variantSku;
            }
        }

        if ($line['product_id'] !== null) {
            $productSku = Product::withoutTenancy(
                fn () => Product::query()->find($line['product_id'])
            )?->sku;

            if (filled($productSku)) {
                return (string) $productSku;
            }
        }

        $reference = $line['external_variant_id']
            ?? $line['external_product_id']
            ?? $line['variant_id']
            ?? $line['product_id']
            ?? null;

        return $reference !== null ? (string) $reference : null;
    }

    /**
     * @param  array<string, mixed>  $options  optional overrides: cod_amount, note, parcel_nature
     *
     * @throws ValidationException readiness/city-mapping problems (the order/setup isn't ready)
     * @throws OzonShipmentCreationException Ozon rejected the parcel, or its response couldn't be parsed
     */
    public function send(Order $order, DeliveryConnection $connection, array $options, User $actor): Shipment
    {
        $this->validateReadiness($order, $connection);

        // Re-resolve rather than threading validateReadiness()'s result
        // through — cheap (in-memory lookups only) and keeps this method
        // self-contained if it's ever called without validateReadiness()
        // running first in the same request.
        $resolution = $this->cityResolver->resolve($order, $connection);

        if (! $resolution['resolved']) {
            throw ValidationException::withMessages(['city' => $resolution['error']]);
        }

        $codAmount = isset($options['cod_amount']) ? (float) $options['cod_amount'] : (float) $order->total;

        $settings = $connection->settings ?? [];
        $stock = self::resolveParcelStock($settings);
        // Sent whenever line SKUs are available regardless of stock mode —
        // Ozon documents products as optional, and it's known to help
        // stock-parcel compatibility even outside the strictly-required
        // parcel-stock=1 case.
        $products = $this->buildProductsPayload($order);

        $connector = new OzonExpressConnector($connection);

        $result = $connector->createShipment($order, $connection, [
            'receiver_name' => $order->customer_name,
            'phone' => $order->customer_phone,
            'provider_city_id' => $resolution['provider_city_id'],
            'address' => $order->confirmed_shipping_address,
            'cod_amount' => $codAmount,
            'note' => $options['note'] ?? ($settings['default_note'] ?? null),
            'parcel_nature' => $options['parcel_nature'] ?? ($settings['default_parcel_nature'] ?? null),
            'parcel_stock' => $stock,
            'parcel_open' => self::resolveParcelOpen($settings),
            'fragile' => self::resolveBooleanFlag($settings, 'default_fragile'),
            'replace' => self::resolveBooleanFlag($settings, 'default_replace'),
            'products' => $products,
        ]);

        if (! $result['ok']) {
            throw new OzonShipmentCreationException(
                $result['error'] ?? 'Ozon Express did not accept this parcel.',
                $result['debug'] ?? [],
            );
        }

        // add-parcel returning a tracking number is NOT trusted alone — see
        // OzonExpressConnector::verifyShipment(). Only a VERIFIED shipment
        // is bridged into DispatchService::assign() (which is what actually
        // moves the order out of "awaiting carrier"); an unverified one is
        // still persisted (for diagnostics + retry) but the order stays
        // exactly where it was.
        $verification = $result['verification'] ?? ['verified' => false, 'verification_error' => 'Verification was not attempted.'];
        $verified = (bool) ($verification['verified'] ?? false);

        $shipment = DB::transaction(function () use ($order, $connection, $resolution, $codAmount, $result, $verification, $verified, $actor) {
            $shipment = Shipment::updateOrCreate(
                [
                    'store_id' => $order->store_id,
                    'shippable_type' => Order::class,
                    'shippable_id' => $order->getKey(),
                    'provider_code' => 'ozon',
                ],
                [
                    'organization_id' => $order->organization_id,
                    'delivery_connection_id' => $connection->id,
                    'provider_shipment_id' => $result['provider_shipment_id'] ?? null,
                    'tracking_number' => $result['tracking_number'],
                    'status' => $verified ? Shipment::STATUS_SENT_TO_CARRIER : Shipment::STATUS_PROVIDER_UNVERIFIED,
                    'provider_status' => $verified ? null : 'unverified',
                    'receiver_name' => $order->customer_name,
                    'phone' => $order->customer_phone,
                    'city_id' => $resolution['provider_city_record_id'],
                    'city_name' => $resolution['provider_city_name'],
                    'address' => $order->confirmed_shipping_address,
                    'cod_amount' => $codAmount,
                    'raw_payload' => [
                        'add_parcel' => $result['raw'],
                        'add_parcel_result' => $result['add_parcel_result'] ?? null,
                        'add_parcel_message' => $result['add_parcel_message'] ?? null,
                        'verification' => $verification,
                    ],
                    'sent_at' => now(),
                ],
            );

            if ($verified) {
                $orderShipment = $this->dispatch->assign($order, [
                    'carrier_type' => 'courier',
                    'carrier_name' => 'Ozon Express',
                    'tracking_number' => $shipment->tracking_number,
                    'notes' => "Ozon Express shipment {$shipment->tracking_number}",
                ], $actor);

                $shipment->update(['order_shipment_id' => $orderShipment->id]);
            }

            return $shipment->refresh();
        });

        // Freeze the carrier fee snapshot at hand-off. Idempotent (a no-op
        // once fee_calculated_at is set — see snapshotForShipment()) and
        // never throws. Resolves the fee from the provider-city tariff /
        // city override / provider default / manual ladder — NEVER an Ozon
        // add-parcel response fee (unconfirmed; see the delivery audit).
        $this->feeCalculator->snapshotForShipment($shipment);

        return $shipment->refresh();
    }

    /**
     * Re-checks a shipment stuck at STATUS_PROVIDER_UNVERIFIED against
     * parcel-info/tracking. If now confirmed, promotes it to
     * sent_to_carrier and — for the first time for this shipment — bridges
     * it into DispatchService::assign(), moving the order out of "awaiting
     * carrier" only now, never at the original unverified attempt.
     *
     * A shipment already verified (or otherwise not awaiting verification)
     * is returned unchanged — this is a safe no-op to re-call.
     *
     * @throws ValidationException not an Ozon shipment, or has no tracking number to verify
     */
    public function retryVerification(Shipment $shipment, User $actor): Shipment
    {
        if (! $shipment->isOzon()) {
            throw ValidationException::withMessages(['shipment' => 'This is not an Ozon shipment.']);
        }

        if ($shipment->status !== Shipment::STATUS_PROVIDER_UNVERIFIED) {
            return $shipment;
        }

        if (blank($shipment->tracking_number)) {
            throw ValidationException::withMessages(['shipment' => 'This shipment has no tracking number to verify.']);
        }

        $connector = new OzonExpressConnector($shipment->connection);
        $verification = $connector->verifyShipment($shipment->tracking_number);
        $verified = (bool) $verification['verified'];

        return DB::transaction(function () use ($shipment, $verification, $verified, $actor) {
            $rawPayload = $shipment->raw_payload ?? [];
            $rawPayload['verification'] = $verification;

            $shipment->update([
                'status' => $verified ? Shipment::STATUS_SENT_TO_CARRIER : Shipment::STATUS_PROVIDER_UNVERIFIED,
                'provider_status' => $verified ? null : 'unverified',
                'raw_payload' => $rawPayload,
            ]);

            if ($verified) {
                $order = $shipment->shippable;

                $orderShipment = $this->dispatch->assign($order, [
                    'carrier_type' => 'courier',
                    'carrier_name' => 'Ozon Express',
                    'tracking_number' => $shipment->tracking_number,
                    'notes' => "Ozon Express shipment {$shipment->tracking_number}",
                ], $actor);

                $shipment->update(['order_shipment_id' => $orderShipment->id]);
            }

            return $shipment->refresh();
        });
    }

    /**
     * Admin-safe verification debug details for the UI — built from what
     * send()/retryVerification() already stored on the shipment. Never
     * includes the api_key or a full request URL (raw_payload itself never
     * carries either — see OzonExpressConnector's own doc comments).
     *
     * @return array{
     *     tracking_number_returned: ?string, add_parcel_result: ?string, add_parcel_message: ?string,
     *     parcel_info_http_status: ?int, parcel_info_provider_message: ?string,
     *     tracking_http_status: ?int, tracking_provider_message: ?string,
     *     verification_status: string, verification_error: ?string,
     * }
     */
    public static function verificationDebug(Shipment $shipment): array
    {
        $raw = $shipment->raw_payload ?? [];
        $verification = $raw['verification'] ?? [];

        return [
            'tracking_number_returned' => $shipment->tracking_number,
            'add_parcel_result' => $raw['add_parcel_result'] ?? null,
            'add_parcel_message' => $raw['add_parcel_message'] ?? null,
            'parcel_info_http_status' => $verification['parcel_info_http_status'] ?? null,
            'parcel_info_provider_message' => $verification['parcel_info_provider_message'] ?? null,
            'tracking_http_status' => $verification['tracking_http_status'] ?? null,
            'tracking_provider_message' => $verification['tracking_provider_message'] ?? null,
            'verification_status' => $shipment->status === Shipment::STATUS_SENT_TO_CARRIER ? 'verified' : 'unverified',
            'verification_error' => $verification['verification_error'] ?? null,
        ];
    }
}
