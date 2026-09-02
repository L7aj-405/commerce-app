<?php

declare(strict_types=1);

namespace App\Http\Controllers\Dashboard\Payroll;

use App\Enums\EmployeeEmploymentStatus;
use App\Enums\EmployeeRoleType;
use App\Http\Controllers\Controller;
use App\Http\Requests\Payroll\EmployeeRequest;
use App\Http\Requests\Payroll\EmployeeSalaryProfileRequest;
use App\Models\Employee;
use App\Models\FinanceAccount;
use App\Models\User;
use App\Services\Payroll\EmployeeSalaryService;
use App\Services\Payroll\EmployeeService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class EmployeeController extends Controller
{
    public function index(Request $request, EmployeeService $service): Response
    {
        $this->authorize('viewAny', Employee::class);

        $organization = $request->user()->getActiveStore()?->organization;
        abort_if($organization === null, 422, 'No active organization.');

        $filters = $request->only(['store_id', 'role_type', 'employment_status', 'search']);

        $employees = $service->filteredQuery($organization, $filters)->paginate(20)->withQueryString();

        return Inertia::render('Dashboard/Payroll/Employees/Index', [
            'employees' => $employees,
            'filters' => $filters,
            'options' => $this->formOptions($request),
            'can' => ['manage' => $request->user()->can('create', Employee::class)],
        ]);
    }

    public function create(Request $request): Response
    {
        $this->authorize('create', Employee::class);

        return Inertia::render('Dashboard/Payroll/Employees/Create', [
            'options' => $this->formOptions($request),
        ]);
    }

    public function store(EmployeeRequest $request, EmployeeService $service): RedirectResponse
    {
        $store = $request->user()->getActiveStore();
        abort_if($store?->organization === null, 422, 'No active organization.');

        $service->create($store->organization, $request->user(), $request->validated());

        return redirect()->route('dashboard.employees.index')->with('success', 'Employee added.');
    }

    public function edit(Request $request, Employee $employee, EmployeeSalaryService $salaries): Response
    {
        $this->authorize('view', $employee);

        $employee->load(['store:id,name', 'user:id,name,email', 'salaryProfiles', 'advances' => fn ($q) => $q->orderByDesc('advance_date')]);

        return Inertia::render('Dashboard/Payroll/Employees/Edit', [
            'employee' => $employee,
            'options' => $this->formOptions($request),
            'can' => [
                'manage' => $request->user()->can('update', $employee),
                'manage_payroll' => $request->user()->hasStorePermission($request->user()->getActiveStore(), 'finance.manage_payroll'),
            ],
        ]);
    }

    public function update(EmployeeRequest $request, Employee $employee, EmployeeService $service): RedirectResponse
    {
        $service->update($employee, $request->validated());

        return redirect()->route('dashboard.employees.index')->with('success', 'Employee updated.');
    }

    public function destroy(Request $request, Employee $employee): RedirectResponse
    {
        $this->authorize('delete', $employee);

        $employee->delete();

        return back()->with('success', 'Employee removed.');
    }

    public function linkUser(Request $request, Employee $employee, EmployeeService $service): RedirectResponse
    {
        $this->authorize('update', $employee);

        $validated = $request->validate(['user_id' => ['required', 'string']]);
        $service->linkUser($employee, $validated['user_id']);

        return back()->with('success', 'User account linked.');
    }

    public function unlinkUser(Request $request, Employee $employee, EmployeeService $service): RedirectResponse
    {
        $this->authorize('update', $employee);

        $service->unlinkUser($employee);

        return back()->with('success', 'User account unlinked.');
    }

    public function storeSalaryProfile(EmployeeSalaryProfileRequest $request, Employee $employee, EmployeeSalaryService $service): RedirectResponse
    {
        $service->createProfile($employee, $request->user(), $request->validated());

        return back()->with('success', 'Salary profile saved — previous salary kept in history.');
    }

    private function formOptions(Request $request): array
    {
        $organization = $request->user()->getActiveStore()?->organization;

        // Users already in this organization who aren't linked to an active
        // employee yet — the realistic "link an existing account" pool.
        $linkedUserIds = Employee::query()
            ->where('organization_id', $organization?->id)
            ->where('employment_status', EmployeeEmploymentStatus::Active->value)
            ->whereNotNull('user_id')
            ->pluck('user_id');

        $linkableUsers = $organization
            ? User::query()
                ->whereHas('organizations', fn ($q) => $q->where('organizations.id', $organization->id))
                ->whereNotIn('id', $linkedUserIds)
                ->orderBy('name')
                ->get(['id', 'name', 'email'])
            : collect();

        return [
            'stores' => $organization?->stores()->orderBy('name')->get(['id', 'name']) ?? collect(),
            'roleTypes' => collect(EmployeeRoleType::cases())->map(fn (EmployeeRoleType $t) => ['value' => $t->value, 'label' => $t->label()])->values(),
            'employmentStatuses' => collect(EmployeeEmploymentStatus::cases())->map(fn (EmployeeEmploymentStatus $t) => ['value' => $t->value, 'label' => $t->label()])->values(),
            'linkableUsers' => $linkableUsers,
            'accounts' => FinanceAccount::query()->where('is_active', true)->orderBy('name')->get(['id', 'name', 'type']),
        ];
    }
}
