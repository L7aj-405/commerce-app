<?php

declare(strict_types=1);

namespace App\Services\Documents;

use App\Enums\FulfillmentDocumentType;
use App\Enums\FulfillmentStatus;
use App\Models\FulfillmentDocument;
use App\Models\Order;
use App\Models\User;
use App\Services\Delivery\FulfillmentDocumentService;
use App\Services\Pos\DocumentGenerationService;
use Illuminate\Support\Collection;
use Illuminate\Validation\ValidationException;

/**
 * The internal pick/pack ticket workflow:
 *   - decide whether an order is far enough along to have one;
 *   - render it (on demand, the default);
 *   - optionally store a private copy as a FulfillmentDocument.
 *
 * NEVER changes order status, calls a provider API, moves stock, or writes
 * a finance transaction — it is a read-only render of the order's own data.
 */
class PickPackTicketService
{
    /** Statuses at which a pick/pack ticket is meaningful — confirmed and anything past it. */
    public const ELIGIBLE_STATUSES = [
        FulfillmentStatus::Confirmed,
        FulfillmentStatus::WaitingForStock,
        FulfillmentStatus::ReadyForPicking,
        FulfillmentStatus::Picking,
        FulfillmentStatus::Packing,
        FulfillmentStatus::InProgress,
        FulfillmentStatus::ReadyForDelivery,
        FulfillmentStatus::Delivered,
        FulfillmentStatus::Completed,
    ];

    public function __construct(
        private readonly DocumentGenerationService $documents,
        private readonly FulfillmentDocumentService $store,
    ) {}

    public function isEligible(Order $order): bool
    {
        $status = $order->fulfillment_status ?? FulfillmentStatus::Pending;

        return in_array($status, self::ELIGIBLE_STATUSES, true);
    }

    /** @throws ValidationException when the order is not confirmed yet */
    public function assertEligible(Order $order): void
    {
        if ($this->isEligible($order)) {
            return;
        }

        $status = $order->fulfillment_status ?? FulfillmentStatus::Pending;

        $message = $status === FulfillmentStatus::Pending
            ? 'Order must be confirmed before printing a pick/pack ticket.'
            : "A pick/pack ticket is not available for an order that is {$status->label()}.";

        throw ValidationException::withMessages(['order' => $message]);
    }

    /** @throws ValidationException */
    public function render(Order $order, ?User $actor = null): string
    {
        $this->assertEligible($order);

        return $this->documents->renderPickPackTicket($order, $actor);
    }

    /**
     * @param  Collection<int, Order>  $orders
     * @throws ValidationException when none of the orders are eligible
     */
    public function renderBatch(Collection $orders, ?User $actor = null): string
    {
        $eligible = $orders->filter(fn (Order $o) => $this->isEligible($o))->values();

        if ($eligible->isEmpty()) {
            throw ValidationException::withMessages([
                'orders' => 'None of the selected orders are confirmed yet, so no pick/pack tickets can be printed.',
            ]);
        }

        return $this->documents->renderPickPackTicketBatch($eligible, $actor);
    }

    /**
     * Store a private copy as a FulfillmentDocument (document_type =
     * pick_ticket, provider_code = null, documentable = Order). Regenerating
     * replaces the prior copy in place — never a finance transaction.
     *
     * @throws ValidationException
     */
    public function storeCopy(Order $order, User $actor): FulfillmentDocument
    {
        $bytes = $this->render($order, $actor);

        return $this->store->storeGeneratedPdf(
            $order,
            FulfillmentDocumentType::PickTicket,
            $bytes,
            [
                'provider_code' => null,
                'generated_by' => $actor->id,
                'metadata' => ['order_reference' => (string) ($order->order_number ?? $order->id)],
            ],
        );
    }
}
