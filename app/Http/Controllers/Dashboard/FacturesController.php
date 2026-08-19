<?php

declare(strict_types=1);

namespace App\Http\Controllers\Dashboard;

use App\Http\Controllers\Controller;
use App\Models\Facture;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use Inertia\Inertia;
use Inertia\Response;
use Spatie\Activitylog\Models\Activity;
use Symfony\Component\HttpFoundation\StreamedResponse;

class FacturesController extends Controller
{
    public function index(Request $request): Response
    {
        $store = $request->user()->getActiveStore();

        if ($store === null) {
            return Inertia::render('Dashboard/Factures', [
                'factures' => ['data' => [], 'links' => []],
                'stats'    => $this->emptyStats(),
                'filters'  => [],
            ]);
        }

        $filters = [
            'status'         => $request->input('status'),
            'payment_status' => $request->input('payment_status'),
            'search'         => $request->input('search'),
        ];

        $factures = Facture::query()
            ->where('store_id', $store->id)
            ->when($request->filled('status'),         fn ($q) => $q->where('status', $filters['status']))
            ->when($request->filled('payment_status'), fn ($q) => $q->where('payment_status', $filters['payment_status']))
            ->when($request->filled('search'), function ($q) use ($filters) {
                $term = '%' . $filters['search'] . '%';
                $q->where(function ($q) use ($term) {
                    $q->where('invoice_number', 'like', $term)
                      ->orWhere('customer_name', 'like', $term)
                      ->orWhere('customer_email', 'like', $term);
                });
            })
            ->orderByDesc('invoice_date')
            ->paginate(15)
            ->withQueryString();

        $stats = $this->computeStats($store->id);

        return Inertia::render('Dashboard/Factures', [
            'factures' => $factures,
            'stats'    => $stats,
            'filters'  => $filters,
        ]);
    }

    public function show(Request $request, Facture $facture): Response
    {
        $this->authorize('view', $facture);

        $facture->load(['items', 'store:id,name,country,currency']);
        $issuerName = $facture->issued_by ? \App\Models\User::whereKey($facture->issued_by)->value('name') : null;

        $activities = Activity::query()
            ->where('subject_type', Facture::class)
            ->where('subject_id', $facture->id)
            ->with('causer:id,name')
            ->latest()
            ->get()
            ->map(fn (Activity $a): array => [
                'id'          => $a->id,
                'event'       => $a->event ?? $a->description,
                'description' => $a->description,
                'causer'      => $a->causer?->name ?? 'System',
                'created_at'  => $a->created_at,
                'reason'      => $a->properties['reason'] ?? null,
                'old'         => $a->properties['old'] ?? null,
                'new'         => $a->properties['attributes'] ?? null,
            ]);

        return Inertia::render('Dashboard/FacturesDetail', [
            'facture'    => $facture,
            'issuedByName' => $issuerName,
            'activities' => $activities,
            'can'        => [
                'amend' => Gate::allows('amend', $facture),
                'void'  => Gate::allows('void', $facture),
                'pay'   => Gate::allows('update', $facture),
            ],
        ]);
    }

    public function download(Request $request, Facture $facture): StreamedResponse
    {
        // TODO: Gate::authorize('download', $facture) once FacturePolicy exists.
        $this->ensureOwnership($request, $facture);

        abort_if(
            $facture->pdf_path === null || ! Storage::disk('local')->exists($facture->pdf_path),
            404,
            'Invoice PDF is not available.',
        );

        return Storage::disk('local')->download(
            $facture->pdf_path,
            "Facture-{$facture->invoice_number}.pdf",
        );
    }

    private function computeStats(string $storeId): array
    {
        $base = Facture::query()->where('store_id', $storeId);

        return [
            'total'         => (clone $base)->count(),
            'paid'          => (clone $base)->where('payment_status', 'paid')->count(),
            'unpaid'        => (clone $base)->where('payment_status', '!=', 'paid')->count(),
            'overdue'       => (clone $base)
                ->where('payment_status', '!=', 'paid')
                ->whereDate('due_date', '<', now())
                ->count(),
            'total_revenue' => (float) (clone $base)->sum('total_amount'),
        ];
    }

    private function emptyStats(): array
    {
        return [
            'total' => 0, 'paid' => 0, 'unpaid' => 0, 'overdue' => 0, 'total_revenue' => 0.0,
        ];
    }

    private function ensureOwnership(Request $request, Facture $facture): void
    {
        $store = $request->user()->getActiveStore();
        abort_if($store === null || $facture->store_id !== $store->id, 403);
    }
}
