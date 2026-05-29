<?php

declare(strict_types=1);

namespace App\Livewire\Stores\Settings;

use App\Models\Order;
use App\Models\Store;
use Illuminate\Contracts\View\View;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\Http;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Attributes\Validate;
use Livewire\Component;
use Livewire\WithPagination;

#[Layout('layouts.app')]
#[Title('WhatsApp Settings')]
class WhatsappSettings extends Component
{
    use WithPagination;
    public Store $store;

    public bool $hasExistingToken = false;

    #[Validate('required|string|max:255')]
    public string $phoneNumberId = '';

    #[Validate('required|string|max:255')]
    public string $businessAccountId = '';

    #[Validate('nullable|string|max:512')]
    public string $accessToken = '';

    #[Validate('nullable|string|max:255')]
    public string $webhookVerifyToken = '';

    public ?string $connectionStatus = null;

    public function mount(Store $store): void
    {
        abort_unless($store->user_id === auth()->id(), 403);

        $this->store = $store;

        $creds = $store->credentials;

        if ($creds) {
            $this->phoneNumberId      = $creds->whatsapp_phone_number_id ?? '';
            $this->businessAccountId  = $creds->whatsapp_business_account_id ?? '';
            $this->webhookVerifyToken = $creds->whatsapp_webhook_verify_token ?? '';
            $this->hasExistingToken   = filled($creds->whatsapp_access_token);
        }
    }

    public function save(): void
    {
        $this->validate();

        $creds = $this->store->getOrCreateCredentials();

        $data = [
            'whatsapp_phone_number_id'      => $this->phoneNumberId ?: null,
            'whatsapp_business_account_id'  => $this->businessAccountId ?: null,
            'whatsapp_webhook_verify_token' => $this->webhookVerifyToken ?: null,
        ];

        if (filled($this->accessToken)) {
            $data['whatsapp_access_token'] = $this->accessToken;
            $this->hasExistingToken = true;
            $this->accessToken = '';
        }

        $creds->update($data);

        $this->connectionStatus = null;

        session()->flash('success', 'WhatsApp settings saved.');
    }

    public function testConnection(): void
    {
        $creds = $this->store->fresh()->credentials;

        if (! $creds || ! $creds->hasWhatsapp()) {
            $this->connectionStatus = 'not_configured';
            return;
        }

        try {
            $response = Http::withToken($creds->whatsapp_access_token)
                ->timeout(8)
                ->get("https://graph.facebook.com/v19.0/{$creds->whatsapp_phone_number_id}");

            $this->connectionStatus = $response->successful() ? 'connected' : 'invalid';
        } catch (\Throwable) {
            $this->connectionStatus = 'invalid';
        }
    }

    #[Computed]
    public function stats(): array
    {
        return [
            'sent'      => Order::where('store_id', $this->store->id)->where('whatsapp_message_sent', true)->count(),
            'confirmed' => Order::where('store_id', $this->store->id)->whereNotNull('whatsapp_confirmed_at')->count(),
            'cancelled' => Order::where('store_id', $this->store->id)->whereNotNull('whatsapp_cancelled_at')->count(),
        ];
    }

    #[Computed]
    public function messageHistory(): LengthAwarePaginator
    {
        return Order::where('store_id', $this->store->id)
            ->where('whatsapp_message_sent', true)
            ->latest('whatsapp_sent_at')
            ->paginate(10);
    }

    public function render(): View
    {
        return view('livewire.stores.settings.whatsapp-settings');
    }
}
