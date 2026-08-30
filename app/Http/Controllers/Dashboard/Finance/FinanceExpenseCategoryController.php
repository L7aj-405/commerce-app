<?php

declare(strict_types=1);

namespace App\Http\Controllers\Dashboard\Finance;

use App\Http\Controllers\Controller;
use App\Http\Requests\Finance\FinanceExpenseCategoryRequest;
use App\Models\FinanceExpenseCategory;
use App\Services\Finance\FinanceExpenseCategoryService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class FinanceExpenseCategoryController extends Controller
{
    public function index(Request $request, FinanceExpenseCategoryService $service): Response
    {
        $this->authorize('viewAny', FinanceExpenseCategory::class);

        $organization = $request->user()->getActiveStore()?->organization;
        if ($organization !== null) {
            $service->ensureSeeded($organization);
        }

        $categories = FinanceExpenseCategory::query()
            ->withCount(['expenses', 'recurringExpenses'])
            ->orderByDesc('is_system')
            ->orderBy('name')
            ->get();

        return Inertia::render('Dashboard/Finance/Categories/Index', [
            'categories' => $categories,
        ]);
    }

    public function store(FinanceExpenseCategoryRequest $request, FinanceExpenseCategoryService $service): RedirectResponse
    {
        $store = $request->user()->getActiveStore();
        abort_if($store?->organization === null, 422, 'No active organization.');

        $service->create($store->organization, $request->validated());

        return back()->with('success', 'Category created.');
    }

    public function update(FinanceExpenseCategoryRequest $request, FinanceExpenseCategory $category, FinanceExpenseCategoryService $service): RedirectResponse
    {
        $service->update($category, $request->validated());

        return back()->with('success', 'Category updated.');
    }

    public function destroy(Request $request, FinanceExpenseCategory $category, FinanceExpenseCategoryService $service): RedirectResponse
    {
        $this->authorize('update', $category);

        if ($category->is_system || $category->isInUse()) {
            $service->deactivate($category);

            return back()->with('success', 'Category is in use — deactivated instead of deleted.');
        }

        $this->authorize('delete', $category);
        $service->delete($category);

        return back()->with('success', 'Category deleted.');
    }
}
