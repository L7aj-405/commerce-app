<?php

declare(strict_types=1);

namespace App\Livewire\Stores\Settings;

use App\Models\Store;
use App\Services\Meta\MetaOAuthService;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Component;

#[Layout('layouts.app')]
#[Title('WhatsApp Setup')]
class WhatsAppSetup extends Component
{
    public Store $store;
    public bool $isConnected = false;
    public ?string $connectedPhone = null;

    public function mount(Store $store): void
    {
        abort_unless($store->user_id === auth()->id(), 403);

        $this->store = $store;

        $creds = $store->credentials;

        $this->isConnected   = $creds !== null && $creds->hasWhatsapp();
        $this->connectedPhone = $creds?->whatsapp_phone_number_id;
    }

    public function startOAuth(): void
    {
        $loginUrl = app(MetaOAuthService::class)->getLoginUrl($this->store->id);
        $this->redirect($loginUrl, navigate: false);
    }

    public function disconnect(): void
    {
        $creds = $this->store->credentials;

        if ($creds === null) {
            return;
        }

        $creds->update([
            'whatsapp_access_token'         => null,
            'whatsapp_phone_number_id'       => null,
            'whatsapp_business_account_id'   => null,
            'whatsapp_webhook_verify_token'  => null,
        ]);

        $this->isConnected   = false;
        $this->connectedPhone = null;
    }

    public function render(): View
    {
        return view('livewire.stores.settings.whatsapp-setup');
    }
}
