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
#[Title('WhatsApp Setup')]
class WhatsAppSetupWizard extends Component
{
    public Store $store;

    public int $currentStep = 1;

    public string $method = ''; // 'saas_app' | 'user_app'

    // Step 3: Account identifiers
    public string $phoneNumberId = '';
    public string $businessAccountId = '';

    // Step 4: Access token
    public string $accessToken = '';

    // Step 5: Webhook
    public string $webhookVerifyToken = '';

    public function mount(Store $store): void
    {
        abort_unless($store->user_id === auth()->id(), 403);
        $this->store = $store;
    }

    public function selectMethod(string $method): void
    {
        if (! in_array($method, ['saas_app', 'user_app'], true)) {
            return;
        }

        $this->method = $method;
        $this->resetValidation('method');
    }

    public function nextStep(): void
    {
        if ($this->currentStep === 1) {
            if (empty($this->method)) {
                $this->addError('method', 'Please select a connection method.');
                return;
            }

            if ($this->method === 'saas_app') {
                $loginUrl = app(MetaOAuthService::class)->getLoginUrl($this->store->id);
                $this->redirect($loginUrl, navigate: false);
                return;
            }

            if ($this->method === 'user_app') {
                $this->redirect(route('stores.whatsapp.connect', $this->store), navigate: true);
                return;
            }
        }

        if ($this->currentStep === 3) {
            $this->validate([
                'phoneNumberId'     => ['required', 'string', 'max:255'],
                'businessAccountId' => ['required', 'string', 'max:255'],
            ], [], [
                'phoneNumberId'     => 'Phone Number ID',
                'businessAccountId' => 'Business Account ID',
            ]);
        }

        if ($this->currentStep === 4) {
            $this->validate([
                'accessToken' => ['required', 'string', 'max:2048'],
            ], [], [
                'accessToken' => 'Access Token',
            ]);
        }

        if ($this->currentStep === 5) {
            $this->validate([
                'webhookVerifyToken' => ['nullable', 'string', 'max:255'],
            ]);

            $this->saveCredentials();
        }

        if ($this->currentStep < 6) {
            $this->currentStep++;
        }
    }

    public function prevStep(): void
    {
        if ($this->currentStep > 1) {
            $this->currentStep--;
        }
    }

    private function saveCredentials(): void
    {
        $data = [
            'whatsapp_phone_number_id'     => $this->phoneNumberId,
            'whatsapp_business_account_id' => $this->businessAccountId,
            'whatsapp_access_token'        => $this->accessToken,
            'whatsapp_setup_status'        => 'configured',
            'whatsapp_setup_completed_at'  => now(),
        ];

        if (filled($this->webhookVerifyToken)) {
            $data['whatsapp_webhook_verify_token'] = $this->webhookVerifyToken;
        }

        $this->store->credentials()->updateOrCreate(
            ['store_id' => $this->store->id],
            $data
        );
    }

    public function render(): View
    {
        return view('livewire.stores.whatsapp-setup-wizard', [
            'stepLabels' => ['Choose', 'Meta App', 'IDs', 'Token', 'Webhook', 'Done'],
            'totalSteps' => 6,
        ]);
    }
}
