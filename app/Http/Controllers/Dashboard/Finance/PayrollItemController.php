<?php

declare(strict_types=1);

namespace App\Http\Controllers\Dashboard\Finance;

use App\Http\Controllers\Controller;
use App\Http\Requests\Payroll\PayrollItemUpdateRequest;
use App\Models\EmployeeAdvance;
use App\Models\PayrollItem;
use App\Services\Payroll\EmployeeAdvanceService;
use App\Services\Payroll\PayrollService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class PayrollItemController extends Controller
{
    public function update(PayrollItemUpdateRequest $request, PayrollItem $item, PayrollService $service): RedirectResponse
    {
        $service->updateItem($item, $request->validated());

        return back()->with('success', 'Payroll line updated.');
    }

    public function applyAdvance(Request $request, PayrollItem $item, EmployeeAdvanceService $service): RedirectResponse
    {
        $this->authorize('update', $item);

        $organizationId = $request->user()->getActiveStore()?->organization_id;
        $validated = $request->validate([
            'advance_id' => ['required', 'string', Rule::exists('employee_advances', 'id')->where(fn ($q) => $q->where('organization_id', $organizationId))],
        ]);

        $advance = EmployeeAdvance::query()->findOrFail($validated['advance_id']);
        $service->applyToPayrollItem($advance, $item);

        return back()->with('success', 'Advance deducted from this payslip.');
    }

    public function pay(Request $request, PayrollItem $item, PayrollService $service): RedirectResponse
    {
        $this->authorize('update', $item);

        $organizationId = $request->user()->getActiveStore()?->organization_id;
        $validated = $request->validate([
            'account_id' => ['required', 'string', Rule::exists('finance_accounts', 'id')->where(fn ($q) => $q->where('organization_id', $organizationId))],
            'reference' => ['nullable', 'string', 'max:255'],
        ]);

        $service->pay($item, $request->user(), $validated['account_id'], $validated['reference'] ?? null);

        return back()->with('success', 'Salary paid.');
    }

    public function cancel(Request $request, PayrollItem $item, PayrollService $service): RedirectResponse
    {
        $this->authorize('update', $item);

        $validated = $request->validate(['reason' => ['nullable', 'string', 'max:500']]);
        $service->cancelItem($item, $request->user(), $validated['reason'] ?? null);

        return back()->with('success', 'Payroll line cancelled — any recorded payment was reversed, not deleted.');
    }
}
