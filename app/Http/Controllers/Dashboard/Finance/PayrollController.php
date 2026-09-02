<?php

declare(strict_types=1);

namespace App\Http\Controllers\Dashboard\Finance;

use App\Http\Controllers\Controller;
use App\Http\Requests\Payroll\PayrollPeriodRequest;
use App\Models\FinanceAccount;
use App\Models\PayrollPeriod;
use App\Services\Payroll\PayrollService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

class PayrollController extends Controller
{
    public function index(Request $request): Response
    {
        $this->authorize('viewAny', PayrollPeriod::class);

        $organization = $request->user()->getActiveStore()?->organization;

        $periods = PayrollPeriod::query()
            ->where('organization_id', $organization?->id)
            ->with(['store:id,name'])
            ->withCount('items')
            ->withSum('items as total_net_amount', 'net_amount')
            ->orderByDesc('period_start')
            ->paginate(20)
            ->withQueryString();

        return Inertia::render('Dashboard/Finance/Payroll/Index', [
            'periods' => $periods,
            'options' => $this->formOptions($request),
            'can' => ['manage' => $request->user()->can('create', PayrollPeriod::class)],
        ]);
    }

    public function create(Request $request): Response
    {
        $this->authorize('create', PayrollPeriod::class);

        return Inertia::render('Dashboard/Finance/Payroll/Create', [
            'options' => $this->formOptions($request),
        ]);
    }

    public function store(PayrollPeriodRequest $request, PayrollService $service): RedirectResponse
    {
        $store = $request->user()->getActiveStore();
        abort_if($store?->organization === null, 422, 'No active organization.');

        $period = $service->createPeriod($store->organization, $request->user(), $request->validated());

        return redirect()->route('dashboard.finance.payroll.show', $period)->with('success', 'Payroll period created — calculate it to see salary due.');
    }

    public function show(Request $request, PayrollPeriod $period): Response
    {
        $this->authorize('view', $period);

        $period->load([
            'store:id,name', 'createdBy:id,name', 'approvedBy:id,name',
            'items.employee:id,display_name,role_type,store_id',
            'items.account:id,name',
        ]);

        return Inertia::render('Dashboard/Finance/Payroll/Show', [
            'period' => $period,
            'accounts' => FinanceAccount::query()->where('is_active', true)->orderBy('name')->get(['id', 'name', 'type']),
            'can' => [
                'manage' => $request->user()->can('update', $period),
            ],
        ]);
    }

    public function calculate(Request $request, PayrollPeriod $period, PayrollService $service): RedirectResponse
    {
        $this->authorize('update', $period);

        $service->calculate($period);

        return back()->with('success', 'Payroll calculated — review before approving.');
    }

    public function approve(Request $request, PayrollPeriod $period, PayrollService $service): RedirectResponse
    {
        $this->authorize('update', $period);

        $service->approvePeriod($period, $request->user());

        return back()->with('success', 'Payroll approved — pay items individually or pay all at once.');
    }

    public function payAll(Request $request, PayrollPeriod $period, PayrollService $service): RedirectResponse
    {
        $this->authorize('update', $period);

        $organizationId = $request->user()->getActiveStore()?->organization_id;
        $validated = $request->validate([
            'account_id' => ['required', 'string', Rule::exists('finance_accounts', 'id')->where(fn ($q) => $q->where('organization_id', $organizationId))],
        ]);

        $service->payAll($period, $request->user(), $validated['account_id']);

        return back()->with('success', 'Approved payroll items paid.');
    }

    private function formOptions(Request $request): array
    {
        $organization = $request->user()->getActiveStore()?->organization;

        return [
            'stores' => $organization?->stores()->orderBy('name')->get(['id', 'name']) ?? collect(),
        ];
    }
}
