<?php

declare(strict_types=1);

namespace App\Livewire\Stores;

use App\Models\Store;
use App\Services\Meta\MetaOAuthService;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Component;

#[Layout('layouts.app')]
#[Title('Connect WhatsApp')]
class WhatsAppUserSetup extends Component
{
    public Store $store;

    // token | account | phone | complete
    public string $step = 'token';

    public string $accessToken = '';

    public array $accounts = [];
    public string $selectedBusinessId = '';

    public array $phones = [];
    public string $selectedPhoneId = '';

    public function mount(Store $store): void
    {
        abort_unless($store->user_id === auth()->id(), 403);
        $this->store = $store;
    }

    public function validateToken(): void
    {
        $this->validate([
            'accessToken' => ['required', 'string', 'min:10'],
        ], [], ['accessToken' => 'Access Token']);

        $service = app(MetaOAuthService::class);

        if (! $service->verifyToken($this->accessToken)) {
            $this->addError('accessToken', 'This token is invalid or expired. Verify it in the Meta developer dashboard.');
            return;
        }

        try {
            $accounts = $service->getWhatsAppBusinessAccounts($this->accessToken);
        } catch (\Throwable $e) {
            $this->addError('accessToken', 'Token is valid but failed to fetch business accounts. Ensure it has the whatsapp_business_management scope.');
            return;
        }

        if (empty($accounts)) {
            $this->addError('accessToken', 'No WhatsApp Business Accounts found for this token.');
            return;
        }

        $this->accounts = $accounts;

        // Skip account selector when there is only one
        if (count($accounts) === 1) {
            $this->fetchPhones($accounts[0]['id']);
            return;
        }

        $this->step = 'account';
    }

    public function selectAccount(string $businessId): void
    {
        $this->fetchPhones($businessId);
    }

    public function selectPhone(string $phoneId): void
    {
        $phone = collect($this->phones)->firstWhere('id', $phoneId);

        if (! $phone) {
            $this->addError('selectedPhoneId', 'Invalid selection.');
            return;
        }

        $this->selectedPhoneId = $phoneId;
        $this->persist($phone);
    }

    // ── Helpers ──────────────────────────────────────────────────────────

    private function fetchPhones(string $businessId): void
    {
        $this->selectedBusinessId = $businessId;

        try {
            $phones = app(MetaOAuthService::class)->getPhoneNumbers($this->accessToken, $businessId);
        } catch (\Throwable $e) {
            $this->addError('selectedBusinessId', 'Failed to fetch phone numbers: ' . $e->getMessage());
            return;
        }

        if (empty($phones)) {
            $this->addError('selectedBusinessId', 'No phone numbers found for this account.');
            return;
        }

        $this->phones = $phones;

        // Skip phone selector when there is only one
        if (count($phones) === 1) {
            $this->selectedPhoneId = $phones[0]['id'];
            $this->persist($phones[0]);
            return;
        }

        $this->step = 'phone';
    }

    private function persist(array $phone): void
    {
        $this->store->credentials()->updateOrCreate(
            ['store_id' => $this->store->id],
            [
                'whatsapp_access_token'        => $this->accessToken,
                'whatsapp_phone_number_id'     => $phone['id'],
                'whatsapp_business_account_id' => $this->selectedBusinessId,
                'whatsapp_setup_status'        => 'configured',
                'whatsapp_setup_completed_at'  => now(),
            ]
        );

        $this->step = 'complete';
    }

    public function render(): View
    {
        return view('livewire.stores.whatsapp-user-setup');
    }
}
