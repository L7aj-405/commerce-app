<?php

declare(strict_types=1);

namespace App\Http\Controllers\Pos;

use App\Http\Controllers\Controller;
use App\Models\PosSession;
use App\Services\Pos\SessionManagementService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use RuntimeException;

class SessionController extends Controller
{
    public function open(Request $request, SessionManagementService $sessions): JsonResponse
    {
        $validated = $request->validate([
            'opening_balance' => ['required', 'numeric', 'min:0'],
            'notes'           => ['nullable', 'string'],
        ]);

        $store   = $request->attributes->get('pos_store');
        $cashier = $request->user();

        try {
            $session = $sessions->openSession(
                $store,
                $cashier,
                (float) $validated['opening_balance'],
                $validated['notes'] ?? null,
            );
        } catch (RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->json(['session' => $session], 201);
    }

    public function close(Request $request, SessionManagementService $sessions): JsonResponse
    {
        $validated = $request->validate([
            'session_id'      => ['nullable', 'string', 'exists:pos_sessions,id'],
            'closing_balance' => ['required', 'numeric', 'min:0'],
            'notes'           => ['nullable', 'string'],
        ]);

        $store   = $request->attributes->get('pos_store');
        $cashier = $request->user();

        $session = PosSession::query()
            ->when(
                isset($validated['session_id']),
                fn ($q) => $q->where('id', $validated['session_id']),
                fn ($q) => $q->where('cashier_id', $cashier->id)
                             ->where('status', 'open')
                             ->latest('opened_at'),
            )
            ->where('store_id', $store->id)
            ->first();

        if ($session === null) {
            return response()->json(['message' => 'No open session found.'], 404);
        }

        try {
            $summary = $sessions->closeSession(
                $session,
                (float) $validated['closing_balance'],
                $validated['notes'] ?? null,
            );
        } catch (RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->json($summary);
    }
}
