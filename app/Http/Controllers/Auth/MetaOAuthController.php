<?php

declare(strict_types=1);

namespace App\Http\Controllers\Auth;

use App\Models\Store;
use App\Services\Meta\MetaOAuthService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Redirect;
use Illuminate\View\View;

class MetaOAuthController
{
    public function __construct(
        private MetaOAuthService $oauthService,
    ) {}

    public function handleMetaCallback(Request $request): RedirectResponse
    {
        $code  = $request->query('code');
        $state = $request->query('state');
        $error = $request->query('error');

        if ($error || ! $code || ! $state) {
            return Redirect::route('stores.index')
                ->with('error', 'WhatsApp authorization was cancelled or failed. Please try again.');
        }

        try {
            $storeId = decrypt($state);
            $store   = Store::findOrFail($storeId);

            abort_unless($store->user_id === auth()->id(), 403);

            $tokenData   = $this->oauthService->exchangeCodeForToken($code);
            $accessToken = $tokenData['access_token'];

            $businesses = $this->oauthService->getWhatsAppBusinessAccounts($accessToken);

            if (empty($businesses)) {
                return Redirect::route('stores.whatsapp.setup', $store)
                    ->with('error', 'No WhatsApp Business Account found. Make sure your Facebook account is linked to a WhatsApp Business Account.');
            }

            // Persist OAuth state in session for the selection steps
            session(['meta_oauth' => [
                'store_id'   => $store->id,
                'token'      => $accessToken,
                'businesses' => $businesses,
            ]]);

            // One business — skip the account selector
            if (count($businesses) === 1) {
                return $this->resolvePhones($store, $accessToken, $businesses[0]['id']);
            }

            return Redirect::route('auth.meta.select-account');

        } catch (\Throwable $e) {
            Log::error('Meta OAuth callback failed', ['error' => $e->getMessage()]);

            return Redirect::route('stores.index')
                ->with('error', 'Authentication failed: ' . $e->getMessage());
        }
    }

    public function showAccountSelector(): View|RedirectResponse
    {
        $oauth = session('meta_oauth');

        if (empty($oauth['businesses'])) {
            return Redirect::route('stores.index')
                ->with('error', 'OAuth session expired. Please reconnect WhatsApp.');
        }

        return view('meta.account-selector', [
            'businesses' => $oauth['businesses'],
        ]);
    }

    public function handleAccountSelection(Request $request): RedirectResponse
    {
        $request->validate(['business_id' => ['required', 'string']]);

        $oauth = session('meta_oauth');

        if (empty($oauth)) {
            return Redirect::route('stores.index')
                ->with('error', 'OAuth session expired. Please reconnect WhatsApp.');
        }

        $store = Store::findOrFail($oauth['store_id']);
        abort_unless($store->user_id === auth()->id(), 403);

        return $this->resolvePhones($store, $oauth['token'], $request->input('business_id'));
    }

    public function showNumberSelector(): View|RedirectResponse
    {
        $oauth = session('meta_oauth');

        if (empty($oauth['phones'])) {
            return Redirect::route('stores.index')
                ->with('error', 'OAuth session expired. Please reconnect WhatsApp.');
        }

        return view('meta.number-selector', [
            'phones' => $oauth['phones'],
        ]);
    }

    public function handleNumberSelection(Request $request): RedirectResponse
    {
        $request->validate(['phone_id' => ['required', 'string']]);

        $oauth = session('meta_oauth');

        if (empty($oauth)) {
            return Redirect::route('stores.index')
                ->with('error', 'OAuth session expired. Please reconnect WhatsApp.');
        }

        $store = Store::findOrFail($oauth['store_id']);
        abort_unless($store->user_id === auth()->id(), 403);

        $phone = collect($oauth['phones'])->firstWhere('id', $request->input('phone_id'));

        if (! $phone) {
            return back()->with('error', 'Invalid phone number selected.');
        }

        $this->saveCredentials($store, $oauth['token'], $oauth['business_id'], $phone);
        session()->forget('meta_oauth');

        return Redirect::route('stores.settings.whatsapp', $store)
            ->with('success', 'WhatsApp connected successfully!');
    }

    // ── Helpers ──────────────────────────────────────────────────────────

    private function resolvePhones(Store $store, string $token, string $businessId): RedirectResponse
    {
        try {
            $phones = $this->oauthService->getPhoneNumbers($token, $businessId);
        } catch (\Throwable $e) {
            return Redirect::route('stores.whatsapp.setup', $store)
                ->with('error', 'Failed to fetch phone numbers: ' . $e->getMessage());
        }

        if (empty($phones)) {
            return Redirect::route('stores.whatsapp.setup', $store)
                ->with('error', 'No phone numbers found for this WhatsApp Business Account.');
        }

        // Merge business_id + phones into the session
        session(['meta_oauth' => array_merge(session('meta_oauth', []), [
            'business_id' => $businessId,
            'phones'      => $phones,
        ])]);

        // One phone — save immediately, skip selector
        if (count($phones) === 1) {
            $this->saveCredentials($store, $token, $businessId, $phones[0]);
            session()->forget('meta_oauth');

            return Redirect::route('stores.settings.whatsapp', $store)
                ->with('success', 'WhatsApp connected successfully!');
        }

        return Redirect::route('auth.meta.select-number');
    }

    private function saveCredentials(Store $store, string $token, string $businessId, array $phone): void
    {
        $store->credentials()->updateOrCreate(
            ['store_id' => $store->id],
            [
                'whatsapp_access_token'        => $token,
                'whatsapp_phone_number_id'     => $phone['id'],
                'whatsapp_business_account_id' => $businessId,
                'whatsapp_setup_status'        => 'configured',
                'whatsapp_setup_completed_at'  => now(),
            ]
        );

        Log::info('Meta WhatsApp OAuth connected', [
            'store_id' => $store->id,
            'phone'    => $phone['display_phone_number'] ?? $phone['id'],
        ]);
    }
}
