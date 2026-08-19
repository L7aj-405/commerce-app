<?php

declare(strict_types=1);

namespace App\Http\Controllers\Dashboard;

use App\Http\Controllers\Controller;
use App\Models\BonDeLivraison;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class BonDeLivraisonController extends Controller
{
    public function index(Request $request): Response
    {
        $store = $request->user()->getActiveStore();

        if ($store === null) {
            return Inertia::render('Dashboard/BonDeLivraison', [
                'bons'    => ['data' => [], 'links' => []],
                'stats'   => ['pending' => 0, 'shipped' => 0, 'delivered' => 0],
                'filters' => [],
            ]);
        }

        $filters = [
            'status' => $request->input('status'),
            'search' => $request->input('search'),
        ];

        $bons = BonDeLivraison::query()
            ->where('store_id', $store->id)
            ->when($request->filled('status'), fn ($q) => $q->where('status', $filters['status']))
            ->when($request->filled('search'), function ($q) use ($filters) {
                $term = '%' . $filters['search'] . '%';
                $q->where(function ($q) use ($term) {
                    $q->where('bon_number', 'like', $term)
                      ->orWhere('customer_name', 'like', $term)
                      ->orWhere('tracking_number', 'like', $term);
                });
            })
            ->orderByDesc('issued_date')
            ->paginate(15)
            ->withQueryString();

        $stats = [
            'pending'   => BonDeLivraison::query()->where('store_id', $store->id)->where('status', 'pending')->count(),
            'shipped'   => BonDeLivraison::query()->where('store_id', $store->id)->where('status', 'shipped')->count(),
            'delivered' => BonDeLivraison::query()->where('store_id', $store->id)->where('status', 'delivered')->count(),
        ];

        return Inertia::render('Dashboard/BonDeLivraison', [
            'bons'    => $bons,
            'stats'   => $stats,
            'filters' => $filters,
        ]);
    }

    public function updateStatus(Request $request, BonDeLivraison $bon): RedirectResponse
    {
        $store = $request->user()->getActiveStore();
        abort_if($store === null || $bon->store_id !== $store->id, 403);

        $validated = $request->validate([
            'status' => ['required', 'in:pending,preparing,ready,shipped,delivered,cancelled'],
        ]);

        $updates = ['status' => $validated['status']];

        if ($validated['status'] === 'shipped'   && $bon->shipped_at === null)   $updates['shipped_at']   = now();
        if ($validated['status'] === 'delivered' && $bon->delivered_at === null) $updates['delivered_at'] = now();

        $bon->update($updates);

        return back()->with('success', 'Status updated');
    }
}
