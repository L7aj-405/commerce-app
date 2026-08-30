<?php

declare(strict_types=1);

namespace App\Http\Controllers\Dashboard\Finance;

use App\Http\Controllers\Controller;
use App\Models\FinanceExpense;
use App\Services\Finance\FinanceMonthlyStatementService;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class FinanceMonthlyStatementController extends Controller
{
    public function index(Request $request, FinanceMonthlyStatementService $service): Response
    {
        $this->authorize('viewAny', FinanceExpense::class);

        $request->validate([
            'month' => ['nullable', 'date_format:Y-m'],
            'store_id' => ['nullable', 'string'],
        ]);

        $month = $request->input('month') ?? CarbonImmutable::now()->format('Y-m');
        $storeId = $request->input('store_id');

        $organization = $request->user()->getActiveStore()?->organization;
        $stores = $organization?->stores()->orderBy('name')->get(['id', 'name']) ?? collect();

        // Reject a store filter that does not belong to the active organization.
        if ($storeId !== null && ! $stores->contains('id', $storeId)) {
            $storeId = null;
        }

        return Inertia::render('Dashboard/Finance/MonthlyStatement', [
            'statement' => $service->forMonth($month, $storeId),
            'stores' => $stores,
        ]);
    }
}
