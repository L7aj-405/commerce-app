<?php

declare(strict_types=1);

namespace App\Http\Controllers\Dashboard\Payroll;

use App\Http\Controllers\Controller;
use App\Http\Requests\Payroll\EmployeeAdvanceRequest;
use App\Models\Employee;
use App\Models\EmployeeAdvance;
use App\Services\Payroll\EmployeeAdvanceService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class EmployeeAdvanceController extends Controller
{
    /** Creating an advance request never touches the ledger — see EmployeeAdvanceService::create(). */
    public function store(EmployeeAdvanceRequest $request, Employee $employee, EmployeeAdvanceService $service): RedirectResponse
    {
        $this->authorize('update', $employee);

        $service->create($employee, $request->user(), $request->validated());

        return back()->with('success', 'Advance recorded — not yet paid.');
    }

    public function approve(Request $request, EmployeeAdvance $advance, EmployeeAdvanceService $service): RedirectResponse
    {
        $this->authorize('update', $advance);

        $service->approve($advance, $request->user());

        return back()->with('success', 'Advance approved.');
    }

    /** The only action that actually moves cash — separately gated on finance.manage_payroll, not just employees.manage. */
    public function pay(Request $request, EmployeeAdvance $advance, EmployeeAdvanceService $service): RedirectResponse
    {
        $this->authorize('update', $advance);
        abort_unless($request->user()->hasStorePermission($request->user()->getActiveStore(), 'finance.manage_payroll'), 403, 'You do not have permission to pay advances.');

        $organizationId = $request->user()->getActiveStore()?->organization_id;
        $validated = $request->validate([
            'account_id' => ['required', 'string', Rule::exists('finance_accounts', 'id')->where(fn ($q) => $q->where('organization_id', $organizationId))],
            'reference' => ['nullable', 'string', 'max:255'],
        ]);

        $service->pay($advance, $request->user(), $validated['account_id'], $validated['reference'] ?? null);

        return back()->with('success', 'Advance paid.');
    }

    public function cancel(Request $request, EmployeeAdvance $advance, EmployeeAdvanceService $service): RedirectResponse
    {
        $this->authorize('update', $advance);

        $validated = $request->validate(['reason' => ['nullable', 'string', 'max:500']]);
        $service->cancel($advance, $request->user(), $validated['reason'] ?? null);

        return back()->with('success', 'Advance cancelled.');
    }
}
