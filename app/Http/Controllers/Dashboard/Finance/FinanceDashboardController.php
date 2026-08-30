<?php

declare(strict_types=1);

namespace App\Http\Controllers\Dashboard\Finance;

use App\Http\Controllers\Controller;
use App\Services\Finance\FinanceDashboardService;
use App\Services\Finance\FinanceExpenseCategoryService;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class FinanceDashboardController extends Controller
{
    public function index(Request $request, FinanceDashboardService $dashboard, FinanceExpenseCategoryService $categories): Response
    {
        $organization = $request->user()->getActiveStore()?->organization;
        if ($organization !== null) {
            $categories->ensureSeeded($organization);
        }

        return Inertia::render('Dashboard/Finance/Dashboard', $dashboard->build());
    }
}
