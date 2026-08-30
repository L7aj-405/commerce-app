<?php

declare(strict_types=1);

namespace App\Http\Controllers\Dashboard\Finance;

use App\Http\Controllers\Controller;
use App\Http\Requests\Finance\FinanceCourierDepositRequest;
use App\Models\FinanceCourierDeposit;
use App\Services\Finance\FinanceCourierDepositService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class FinanceCourierDepositController extends Controller
{
    public function store(FinanceCourierDepositRequest $request, FinanceCourierDepositService $service): RedirectResponse
    {
        $store = $request->user()->getActiveStore();
        abort_if($store?->organization === null, 422, 'No active organization.');

        $validated = $request->validated();
        $orderIds = $validated['order_ids'];
        unset($validated['order_ids']);

        $service->create($store->organization, $request->user(), $validated, $orderIds);

        return redirect()->route('dashboard.finance.cod-receivables.index')->with('success', 'Courier cash deposit created as a draft.');
    }

    public function confirm(Request $request, FinanceCourierDeposit $deposit, FinanceCourierDepositService $service): RedirectResponse
    {
        $this->authorize('update', $deposit);

        $service->confirm($deposit);

        return back()->with('success', 'Courier deposit confirmed — receivables closed and cash recorded.');
    }

    public function cancel(Request $request, FinanceCourierDeposit $deposit, FinanceCourierDepositService $service): RedirectResponse
    {
        $this->authorize('update', $deposit);

        $service->cancel($deposit);

        return back()->with('success', 'Courier deposit cancelled.');
    }
}
