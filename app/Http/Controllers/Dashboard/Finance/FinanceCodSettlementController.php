<?php

declare(strict_types=1);

namespace App\Http\Controllers\Dashboard\Finance;

use App\Http\Controllers\Controller;
use App\Http\Requests\Finance\FinanceCodSettlementReconcileRequest;
use App\Http\Requests\Finance\FinanceCodSettlementRequest;
use App\Http\Requests\Finance\FinanceCodSettlementVerifyPeriodRequest;
use App\Models\FinanceCodSettlement;
use App\Services\Finance\FinanceCodSettlementService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class FinanceCodSettlementController extends Controller
{
    public function store(FinanceCodSettlementRequest $request, FinanceCodSettlementService $service): RedirectResponse
    {
        $store = $request->user()->getActiveStore();
        abort_if($store?->organization === null, 422, 'No active organization.');

        $validated = $request->validated();
        $orderIds = $validated['order_ids'];
        unset($validated['order_ids']);

        $service->create($store->organization, $request->user(), $validated, $orderIds);

        return redirect()->route('dashboard.finance.cod-receivables.index')->with('success', 'External COD settlement created as a draft.');
    }

    public function settle(Request $request, FinanceCodSettlement $settlement, FinanceCodSettlementService $service): RedirectResponse
    {
        $this->authorize('update', $settlement);

        $service->settle($settlement);

        return back()->with('success', 'COD settlement confirmed — receivables closed and net cash recorded.');
    }

    public function cancel(Request $request, FinanceCodSettlement $settlement, FinanceCodSettlementService $service): RedirectResponse
    {
        $this->authorize('update', $settlement);

        $service->cancel($settlement);

        return back()->with('success', 'COD settlement cancelled.');
    }

    /** Attach the accountant's bank-transfer verification to an existing draft and finalize it — see FinanceCodSettlementService::reconcile(). */
    public function reconcile(FinanceCodSettlementReconcileRequest $request, FinanceCodSettlement $settlement, FinanceCodSettlementService $service): RedirectResponse
    {
        $settled = $service->reconcile($settlement, $request->validated());

        $message = match ($settled->status->value) {
            'partial' => 'Reconciled — received less than expected, recorded as partial.',
            'disputed' => 'Reconciled — received amount differs from expected, flagged as disputed.',
            default => 'COD settlement reconciled — receivables closed and cash recorded.',
        };

        return back()->with('success', $message);
    }

    /** One-shot "Verify bank transfer" for a live-computed provider payout period — see FinanceCodSettlementService::verifyProviderPeriod(). */
    public function verifyPeriod(FinanceCodSettlementVerifyPeriodRequest $request, FinanceCodSettlementService $service): RedirectResponse
    {
        $store = $request->user()->getActiveStore();
        abort_if($store?->organization === null, 422, 'No active organization.');

        $validated = $request->validated();
        $orderIds = $validated['order_ids'];
        unset($validated['order_ids']);

        $settled = $service->verifyProviderPeriod($store->organization, $request->user(), $validated, $orderIds);

        $message = match ($settled->status->value) {
            'partial' => 'Payout verified — received less than expected, recorded as partial.',
            'disputed' => 'Payout verified — received amount differs from expected, flagged as disputed.',
            default => 'Payout verified — receivables closed and cash recorded.',
        };

        return back()->with('success', $message);
    }
}
