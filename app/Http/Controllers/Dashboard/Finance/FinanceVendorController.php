<?php

declare(strict_types=1);

namespace App\Http\Controllers\Dashboard\Finance;

use App\Http\Controllers\Controller;
use App\Http\Requests\Finance\FinanceVendorRequest;
use App\Models\FinanceVendor;
use App\Services\Finance\FinanceVendorService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class FinanceVendorController extends Controller
{
    public function index(Request $request): Response
    {
        $this->authorize('viewAny', FinanceVendor::class);

        $vendors = FinanceVendor::query()
            ->withCount(['expenses', 'recurringExpenses'])
            ->orderBy('name')
            ->get();

        return Inertia::render('Dashboard/Finance/Vendors/Index', [
            'vendors' => $vendors,
        ]);
    }

    public function store(FinanceVendorRequest $request, FinanceVendorService $service): RedirectResponse
    {
        $store = $request->user()->getActiveStore();
        abort_if($store?->organization === null, 422, 'No active organization.');

        $service->create($store->organization, $request->validated());

        return back()->with('success', 'Vendor created.');
    }

    public function update(FinanceVendorRequest $request, FinanceVendor $vendor, FinanceVendorService $service): RedirectResponse
    {
        $service->update($vendor, $request->validated());

        return back()->with('success', 'Vendor updated.');
    }

    public function destroy(Request $request, FinanceVendor $vendor, FinanceVendorService $service): RedirectResponse
    {
        $this->authorize('update', $vendor);

        if ($vendor->isInUse()) {
            $service->deactivate($vendor);

            return back()->with('success', 'Vendor is in use — deactivated instead of deleted.');
        }

        $this->authorize('delete', $vendor);
        $service->delete($vendor);

        return back()->with('success', 'Vendor deleted.');
    }
}
