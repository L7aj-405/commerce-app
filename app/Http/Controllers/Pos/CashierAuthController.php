<?php

declare(strict_types=1);

namespace App\Http\Controllers\Pos;

use App\Http\Controllers\Controller;
use App\Models\CashierAccount;
use App\Models\Store;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

class CashierAuthController extends Controller
{
    private const MAX_FAILED_ATTEMPTS = 5;
    private const LOCK_DURATION_MINUTES = 15;

    /**
     * Render the PIN-entry screen. Lists every store that has at least one
     * active CashierAccount so the cashier can pick where they're working.
     */
    public function showLogin(Request $request): Response
    {
        $storeIds = CashierAccount::query()
            ->where('status', 'active')
            ->pluck('store_id')
            ->unique()
            ->all();

        $stores = Store::query()
            ->whereIn('id', $storeIds)
            ->orderBy('name')
            ->get(['id', 'name'])
            ->map(fn (Store $s) => ['id' => $s->id, 'name' => $s->name])
            ->values();

        // First-time enrolment needs to target a store that may not have any
        // cashier accounts yet, so offer every active store for that flow.
        $setupStores = Store::query()
            ->where('status', 'active')
            ->orderBy('name')
            ->get(['id', 'name'])
            ->map(fn (Store $s) => ['id' => $s->id, 'name' => $s->name])
            ->values();

        return Inertia::render('Pos/CashierLogin', [
            'stores'         => $stores,
            'setupStores'    => $setupStores,
            'defaultStoreId' => $stores->first()['id'] ?? null,
        ]);
    }

    /**
     * Verify a 4-digit PIN against bcrypt-hashed pin_codes for the chosen store.
     * On success: bumps last_login_at, logs in the underlying User, stamps the
     * session, redirects to /pos. On failure: increments failed attempts and
     * locks the matched account at MAX_FAILED_ATTEMPTS.
     */
    public function login(Request $request)
{
    $validated = $request->validate([
        'pin_code' => 'required|numeric|digits:4',
        'store_id' => 'required|exists:stores,id',
        'opening_balance' => 'nullable|numeric|min:0', // Ask for opening cash
    ]);

    // Check the PIN against EVERY cashier account in the store — bcrypt hashes
    // have unique salts, so there's no way to look one up by hash. The str_starts_with
    // guard avoids Hash::check() throwing on any legacy non-bcrypt value.
    $candidates = CashierAccount::where('store_id', $validated['store_id'])
        ->with(['user', 'store'])
        ->get();

    $cashier = $candidates->first(function (CashierAccount $c) use ($validated): bool {
        return is_string($c->pin_code)
            && str_starts_with($c->pin_code, '$2')
            && Hash::check($validated['pin_code'], $c->pin_code);
    });

    if ($cashier === null) {
        // Unknown which account was targeted — rate-limit the whole store.
        $this->bumpAllFailures($candidates->filter(fn (CashierAccount $c) => $c->isActive()));

        return redirect()->back()
            ->withErrors(['pin_code' => 'Invalid PIN code.'])
            ->withInput();
    }

    if ($cashier->isLocked()) {
        return redirect()->back()
            ->withErrors(['pin_code' => 'This account is locked. Please try again later.'])
            ->withInput();
    }

    if ($cashier->status !== 'active') {
        return redirect()->back()
            ->withErrors(['pin_code' => 'Your cashier account is not active.'])
            ->withInput();
    }

    $cashier->update([
        'last_login_at'         => now(),
        'failed_login_attempts' => 0,
        'locked_until'          => null,
    ]);

    return $this->establishSession($cashier, (float) ($validated['opening_balance'] ?? 0));
}

    /**
     * First-time PIN enrolment. A member who has POS access but no cashier
     * account yet authenticates with their normal email + password, then
     * chooses their own 4-digit PIN. Requiring the password stops anyone
     * else from claiming an un-enrolled cashier's PIN slot.
     */
    public function setupPin(Request $request)
    {
        $validated = $request->validate([
            'store_id' => 'required|exists:stores,id',
            'email'    => 'required|email',
            'password' => 'required|string',
            'pin_code' => 'required|numeric|digits:4|confirmed',
        ]);

        $user = User::where('email', $validated['email'])->first();

        if ($user === null || ! Hash::check($validated['password'], $user->password)) {
            return back()->withErrors(['email' => 'Invalid email or password.'])->withInput();
        }

        $store = Store::find($validated['store_id']);

        // Must be the owner, or an active member whose role grants pos.access.
        if (! $user->hasStorePermission($store, 'pos.access')) {
            return back()->withErrors(['email' => 'You do not have POS access for this store.'])->withInput();
        }

        // Enrolment is first-time only. If an account already exists, an admin
        // must reset it (self-service can't overwrite an established PIN).
        $existing = CashierAccount::where('store_id', $store->id)->where('user_id', $user->id)->first();

        if ($existing !== null) {
            return back()->withErrors([
                'pin_code' => 'A PIN is already set for this account. Sign in with it, or ask an admin to reset it.',
            ])->withInput();
        }

        $cashier = CashierAccount::create([
            'store_id'              => $store->id,
            'user_id'               => $user->id,
            'pin_code'              => $validated['pin_code'],
            'status'                => 'active',
            'failed_login_attempts' => 0,
            'locked_until'          => null,
        ]);

        $cashier->setRelation('user', $user);
        $cashier->setRelation('store', $store);

        return $this->establishSession($cashier, 0.0);
    }

    /**
     * Shared post-authentication steps: log the underlying user in, auto-open a
     * POS session if none is open, stamp the PIN session keys, redirect to /pos.
     */
    private function establishSession(CashierAccount $cashier, float $openingBalance): RedirectResponse
    {
        Auth::login($cashier->user);

        $store = $cashier->store;

        $existingSession = $store->posSessions()
            ->where('cashier_id', $cashier->user_id)
            ->where('status', 'open')
            ->first();

        if ($existingSession === null) {
            $store->posSessions()->create([
                'cashier_id'      => $cashier->user_id,
                'status'          => 'open',
                'opening_balance' => $openingBalance,
                'opened_at'       => now(),
            ]);
        }

        session([
            'cashier_id' => $cashier->id,
            'store_id'   => $cashier->store_id,
        ]);

        return redirect('/pos');
    }

    public function logout(Request $request): RedirectResponse
    {
        $cashierAccountId = $request->session()->get('cashier_id')
            ?? $request->session()->get('pos.cashier_account_id');

        Auth::logout();

        $request->session()->invalidate();
        $request->session()->regenerateToken();

        Log::info('Cashier signed out', [
            'cashier_account_id' => $cashierAccountId,
        ]);

        return redirect()->route('pos.login');
    }

    /**
     * On a wrong-PIN attempt we don't know which cashier was being targeted,
     * so we increment the counter on every CashierAccount in the store. Any
     * that hit MAX_FAILED_ATTEMPTS get locked. This rate-limits brute force
     * across the whole store.
     */
    private function bumpAllFailures($candidates): void
    {
        foreach ($candidates as $c) {
            $attempts = ($c->failed_login_attempts ?? 0) + 1;
            $updates  = ['failed_login_attempts' => $attempts];

            if ($attempts >= self::MAX_FAILED_ATTEMPTS) {
                $updates['locked_until'] = now()->addMinutes(self::LOCK_DURATION_MINUTES);

                Log::warning('Cashier account locked after repeated failed PIN attempts', [
                    'cashier_account_id' => $c->id,
                    'attempts'           => $attempts,
                ]);
            }

            $c->update($updates);
        }
    }
}
