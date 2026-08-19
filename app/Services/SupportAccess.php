<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Store;
use App\Models\SupportSession;
use App\Models\User;
use Illuminate\Http\Request;
use Throwable;

class SupportAccess
{
    public const SESSION_KEY = 'platform.support_session_id';

    private bool $resolved = false;

    private ?SupportSession $current = null;

    public function current(?User $user = null): ?SupportSession
    {
        if ($this->resolved) {
            return $this->current;
        }

        $this->resolved = true;
        $user ??= auth()->user();

        if ($user === null || ! $user->isSuperAdmin()) {
            return null;
        }

        try {
            $id = session()->get(self::SESSION_KEY);
        } catch (Throwable) {
            return null;
        }

        if (! is_string($id) || $id === '') {
            return null;
        }

        $support = SupportSession::query()
            ->with(['organization:id,owner_user_id,name', 'store:id,organization_id,name,status'])
            ->whereKey($id)
            ->where('user_id', $user->id)
            ->first();

        if ($support === null) {
            $this->forgetBrowserContext();
            return null;
        }

        if (! $support->isOpen()) {
            if ($support->ended_at === null) {
                $support->forceFill([
                    'ended_at' => now(),
                    'end_reason' => 'expired',
                ])->save();
            }

            $this->forgetBrowserContext();
            return null;
        }

        // The store must still belong to the organization captured when the
        // support session started. This prevents a stale session from crossing
        // a boundary if a store is ever moved to another workspace.
        if ($support->store?->organization_id !== $support->organization_id) {
            $support->forceFill([
                'ended_at' => now(),
                'end_reason' => 'tenant_changed',
            ])->save();
            $this->forgetBrowserContext();
            return null;
        }

        return $this->current = $support;
    }

    public function permitsStore(User $user, ?Store $store): bool
    {
        if (! $user->isSuperAdmin() || $store === null) {
            return false;
        }

        $support = $this->current($user);

        return $support !== null
            && $support->store_id === $store->id
            && $support->organization_id === $store->organization_id;
    }

    public function storeFor(User $user): ?Store
    {
        return $this->current($user)?->store;
    }

    public function start(User $admin, Store $store, string $reason, Request $request, int $minutes = 60): SupportSession
    {
        abort_unless($admin->isSuperAdmin(), 403);
        abort_if($store->organization_id === null, 422, 'Support mode requires an organization-backed store.');

        $this->end($admin, 'replaced');

        $support = SupportSession::create([
            'user_id' => $admin->id,
            'organization_id' => $store->organization_id,
            'store_id' => $store->id,
            'reason' => $reason,
            'started_at' => now(),
            'expires_at' => now()->addMinutes($minutes),
            'ip_address' => $request->ip(),
            'user_agent' => $request->userAgent(),
        ]);

        $request->session()->put(self::SESSION_KEY, $support->id);
        $request->session()->put('store_id', $store->id);
        $request->session()->forget(['pos.store_id', 'pos.cashier_id', 'pos.session_id']);

        activity('support')
            ->causedBy($admin)
            ->performedOn($store)
            ->event('support_started')
            ->withProperties([
                'support_session_id' => $support->id,
                'organization_id' => $support->organization_id,
                'store_id' => $store->id,
                'reason' => $reason,
                'expires_at' => $support->expires_at->toIso8601String(),
                'ip_address' => $request->ip(),
            ])
            ->log('Platform support session started');

        $this->resolved = true;
        $this->current = $support->load(['organization:id,owner_user_id,name', 'store:id,organization_id,name,status']);

        return $support;
    }

    public function end(User $admin, string $reason = 'manual'): ?SupportSession
    {
        $this->resolved = false;
        $this->current = null;
        $support = $this->current($admin);

        if ($support !== null) {
            $support->forceFill([
                'ended_at' => now(),
                'end_reason' => $reason,
            ])->save();

            $activity = activity('support')
                ->causedBy($admin)
                ->event('support_ended')
                ->withProperties([
                    'support_session_id' => $support->id,
                    'organization_id' => $support->organization_id,
                    'store_id' => $support->store_id,
                    'end_reason' => $reason,
                ]);

            if ($support->store !== null) {
                $activity->performedOn($support->store);
            }

            $activity->log('Platform support session ended');
        }

        $this->forgetBrowserContext();
        $this->resolved = true;
        $this->current = null;

        return $support;
    }

    /**
     * Minimal metadata safe to expose to React. The reason is intentionally
     * included because the admin should always be reminded why access exists.
     *
     * @return array<string, mixed>|null
     */
    public function profile(?User $user): ?array
    {
        $support = $user !== null ? $this->current($user) : null;

        if ($support === null) {
            return null;
        }

        return [
            'id' => $support->id,
            'organizationId' => $support->organization_id,
            'organizationName' => $support->organization?->name,
            'storeId' => $support->store_id,
            'storeName' => $support->store?->name,
            'reason' => $support->reason,
            'expiresAt' => $support->expires_at->toIso8601String(),
        ];
    }

    private function forgetBrowserContext(): void
    {
        try {
            session()->forget([
                self::SESSION_KEY,
                'store_id',
                'pos.store_id',
                'pos.cashier_id',
                'pos.session_id',
            ]);
        } catch (Throwable) {
            // Console/domain code may run without an HTTP session.
        }
    }
}
