<?php

declare(strict_types=1);

namespace App\Http\Controllers\Dashboard\Finance;

use App\Http\Controllers\Controller;
use App\Http\Requests\Finance\FinanceAccountRequest;
use App\Models\FinanceAccount;
use App\Services\Finance\FinanceAccountService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class FinanceAccountController extends Controller
{
    public function index(Request $request, FinanceAccountService $service): Response
    {
        $this->authorize('viewAny', FinanceAccount::class);

        $organization = $request->user()->getActiveStore()?->organization;
        if ($organization !== null) {
            $service->ensureSeeded($organization);
        }

        $accounts = FinanceAccount::query()
            ->withCount('transactions')
            ->with('store:id,name')
            ->orderByDesc('is_default')
            ->orderBy('name')
            ->get();

        return Inertia::render('Dashboard/Finance/Accounts/Index', [
            'accounts' => $accounts,
            'stores' => $organization?->stores()->orderBy('name')->get(['id', 'name']) ?? collect(),
        ]);
    }

    public function store(FinanceAccountRequest $request, FinanceAccountService $service): RedirectResponse
    {
        $store = $request->user()->getActiveStore();
        abort_if($store?->organization === null, 422, 'No active organization.');

        $service->create($store->organization, $request->validated());

        return back()->with('success', 'Account created.');
    }

    public function update(FinanceAccountRequest $request, FinanceAccount $account, FinanceAccountService $service): RedirectResponse
    {
        $service->update($account, $request->validated());

        return back()->with('success', 'Account updated.');
    }

    public function destroy(Request $request, FinanceAccount $account, FinanceAccountService $service): RedirectResponse
    {
        $this->authorize('update', $account);

        if ($account->isInUse()) {
            $service->deactivate($account);

            return back()->with('success', 'Account is in use — deactivated instead of deleted.');
        }

        $this->authorize('delete', $account);
        $service->delete($account);

        return back()->with('success', 'Account deleted.');
    }
}
