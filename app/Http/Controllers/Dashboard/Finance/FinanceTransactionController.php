<?php

declare(strict_types=1);

namespace App\Http\Controllers\Dashboard\Finance;

use App\Enums\FinanceTransactionType;
use App\Http\Controllers\Controller;
use App\Http\Requests\Finance\FinanceTransactionAdjustmentRequest;
use App\Models\FinanceAccount;
use App\Models\FinanceTransaction;
use App\Services\Finance\FinanceTransactionService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class FinanceTransactionController extends Controller
{
    public function index(Request $request): Response
    {
        $this->authorize('viewAny', FinanceTransaction::class);

        $filters = $request->only(['from', 'to', 'type', 'direction', 'account_id', 'store_id']);

        $transactions = FinanceTransaction::query()
            ->with(['account:id,name,type', 'store:id,name'])
            ->when($filters['from'] ?? null, fn (Builder $q, $v) => $q->whereDate('occurred_at', '>=', $v))
            ->when($filters['to'] ?? null, fn (Builder $q, $v) => $q->whereDate('occurred_at', '<=', $v))
            ->when($filters['type'] ?? null, fn (Builder $q, $v) => $q->where('type', $v))
            ->when($filters['direction'] ?? null, fn (Builder $q, $v) => $q->where('direction', $v))
            ->when($filters['account_id'] ?? null, fn (Builder $q, $v) => $q->where('account_id', $v))
            ->when($filters['store_id'] ?? null, fn (Builder $q, $v) => $q->where('store_id', $v))
            ->orderByDesc('occurred_at')
            ->orderByDesc('created_at')
            ->paginate(25)
            ->withQueryString();

        $organization = $request->user()->getActiveStore()?->organization;

        return Inertia::render('Dashboard/Finance/Transactions/Index', [
            'transactions' => $transactions,
            'filters' => $filters,
            'options' => [
                'types' => array_map(fn (FinanceTransactionType $t) => ['value' => $t->value, 'label' => $t->label()], FinanceTransactionType::cases()),
                'accounts' => FinanceAccount::query()->where('is_active', true)->orderBy('name')->get(['id', 'name']),
                'stores' => $organization?->stores()->orderBy('name')->get(['id', 'name']) ?? collect(),
            ],
            'can' => [
                'manage_cashflow' => $request->user()->hasStorePermission($request->user()->getActiveStore(), 'finance.manage_cashflow'),
            ],
        ]);
    }

    public function store(FinanceTransactionAdjustmentRequest $request, FinanceTransactionService $service): RedirectResponse
    {
        $store = $request->user()->getActiveStore();
        abort_if($store?->organization === null, 422, 'No active organization.');

        $validated = $request->validated();

        $service->record([
            'organization_id' => $store->organization->id,
            'store_id' => $validated['store_id'] ?? null,
            'account_id' => $validated['account_id'] ?? null,
            'direction' => $validated['direction'],
            'type' => FinanceTransactionType::ManualAdjustment,
            'amount' => $validated['amount'],
            'occurred_at' => $validated['occurred_at'],
            'reference' => $validated['reference'] ?? null,
            'description' => $validated['description'],
            'created_by' => $request->user()->id,
        ]);

        return back()->with('success', 'Adjustment recorded.');
    }
}
