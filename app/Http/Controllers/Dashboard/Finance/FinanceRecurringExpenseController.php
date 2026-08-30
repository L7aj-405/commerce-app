<?php

declare(strict_types=1);

namespace App\Http\Controllers\Dashboard\Finance;

use App\Http\Controllers\Controller;
use App\Http\Requests\Finance\FinanceRecurringExpenseRequest;
use App\Models\FinanceExpenseCategory;
use App\Models\FinanceRecurringExpense;
use App\Models\FinanceVendor;
use App\Services\Finance\FinanceExpenseCategoryService;
use App\Services\Finance\FinanceRecurringExpenseService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class FinanceRecurringExpenseController extends Controller
{
    public function index(Request $request): Response
    {
        $this->authorize('viewAny', FinanceRecurringExpense::class);

        $recurring = FinanceRecurringExpense::query()
            ->with(['category:id,name,color,icon', 'vendor:id,name', 'store:id,name'])
            ->withCount('generatedExpenses')
            ->orderBy('next_due_at')
            ->get();

        return Inertia::render('Dashboard/Finance/Recurring/Index', [
            'recurring' => $recurring,
        ]);
    }

    public function create(Request $request): Response
    {
        $this->authorize('create', FinanceRecurringExpense::class);

        return Inertia::render('Dashboard/Finance/Recurring/Create', [
            'options' => $this->formOptions($request),
        ]);
    }

    public function store(FinanceRecurringExpenseRequest $request, FinanceRecurringExpenseService $service): RedirectResponse
    {
        $store = $request->user()->getActiveStore();
        abort_if($store?->organization === null, 422, 'No active organization.');

        $service->create($store->organization, $request->validated());

        return redirect()->route('dashboard.finance.recurring.index')->with('success', 'Recurring expense created.');
    }

    public function edit(Request $request, FinanceRecurringExpense $recurring): Response
    {
        $this->authorize('update', $recurring);

        return Inertia::render('Dashboard/Finance/Recurring/Edit', [
            'recurring' => $recurring->load(['category:id,name', 'vendor:id,name', 'store:id,name']),
            'options' => $this->formOptions($request),
        ]);
    }

    public function update(FinanceRecurringExpenseRequest $request, FinanceRecurringExpense $recurring, FinanceRecurringExpenseService $service): RedirectResponse
    {
        $service->update($recurring, $request->validated());

        return redirect()->route('dashboard.finance.recurring.index')->with('success', 'Recurring expense updated.');
    }

    public function pause(Request $request, FinanceRecurringExpense $recurring, FinanceRecurringExpenseService $service): RedirectResponse
    {
        $this->authorize('update', $recurring);
        $service->pause($recurring);

        return back()->with('success', 'Recurring expense paused.');
    }

    public function resume(Request $request, FinanceRecurringExpense $recurring, FinanceRecurringExpenseService $service): RedirectResponse
    {
        $this->authorize('update', $recurring);
        $service->resume($recurring);

        return back()->with('success', 'Recurring expense resumed.');
    }

    public function cancel(Request $request, FinanceRecurringExpense $recurring, FinanceRecurringExpenseService $service): RedirectResponse
    {
        $this->authorize('delete', $recurring);
        $service->cancel($recurring);

        return back()->with('success', 'Recurring expense cancelled.');
    }

    private function formOptions(Request $request): array
    {
        $organization = $request->user()->getActiveStore()?->organization;
        if ($organization !== null) {
            app(FinanceExpenseCategoryService::class)->ensureSeeded($organization);
        }

        return [
            'categories' => FinanceExpenseCategory::query()->where('is_active', true)->orderBy('name')->get(['id', 'name']),
            'vendors' => FinanceVendor::query()->where('is_active', true)->orderBy('name')->get(['id', 'name']),
            'stores' => $organization?->stores()->orderBy('name')->get(['id', 'name']) ?? collect(),
        ];
    }
}
