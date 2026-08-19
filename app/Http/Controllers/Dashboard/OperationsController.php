<?php

declare(strict_types=1);

namespace App\Http\Controllers\Dashboard;

use App\Enums\FulfillmentStatus;
use App\Http\Controllers\Controller;
use App\Services\Inventory\InventoryTransferService;
use App\Services\Orders\OperationsQueueService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Focused, single-station queues layered over the existing department
 * workflow — no new engine. Unlike DepartmentController these are scoped by
 * warehouse OPERATOR (OperationsQueueService), not the viewer's single active
 * store, because one warehouse can serve several client stores at once.
 *
 * Claim/release/take-next continue to live on DepartmentController — a
 * warehouse claim is identical work regardless of which of these pages linked
 * to it, so it is not duplicated here.
 */
class OperationsController extends Controller
{
    public function __construct(
        private readonly OperationsQueueService $queues,
    ) {}

    public function waitingStock(Request $request): Response
    {
        return $this->render($request, 'WaitingForStock', [FulfillmentStatus::WaitingForStock]);
    }

    public function picking(Request $request): Response
    {
        return $this->render($request, 'Picking', [FulfillmentStatus::ReadyForPicking, FulfillmentStatus::Picking, FulfillmentStatus::Confirmed, FulfillmentStatus::InProgress]);
    }

    public function packing(Request $request): Response
    {
        return $this->render($request, 'Packing', [FulfillmentStatus::Packing]);
    }

    public function readyForDelivery(Request $request): Response
    {
        return $this->render($request, 'ReadyForDelivery', [FulfillmentStatus::ReadyForDelivery]);
    }

    public function transferReceiving(Request $request): Response
    {
        $user = $request->user();

        return Inertia::render('Dashboard/Operations/TransferReceiving', [
            'transfers'         => $this->queues->transfersToReceive($user),
            'is_agency_context' => $this->queues->isAgencyContext($user),
        ]);
    }

    public function receiveTransfer(Request $request, string $transfer, InventoryTransferService $transfers): RedirectResponse
    {
        $user = $request->user();

        $model = $this->queues->findReceivableTransfer($user, $transfer);
        abort_if($model === null, 404);

        try {
            $transfers->receive($model, $user);
        } catch (ValidationException $e) {
            return back()->with('error', $e->validator->errors()->first());
        }

        return back()->with('success', "Transfer {$model->reference} received.");
    }

    /** @param array<int, FulfillmentStatus> $statuses */
    private function render(Request $request, string $page, array $statuses): Response
    {
        $user = $request->user();

        return Inertia::render("Dashboard/Operations/{$page}", [
            'orders'            => $this->queues->queue($user, $statuses),
            'is_agency_context' => $this->queues->isAgencyContext($user),
        ]);
    }
}
