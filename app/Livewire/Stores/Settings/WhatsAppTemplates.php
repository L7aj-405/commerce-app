<?php

declare(strict_types=1);

namespace App\Livewire\Stores\Settings;

use App\Models\Store;
use App\Services\WhatsApp\MessageTemplates;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Component;

#[Layout('layouts.app')]
#[Title('Message Templates')]
class WhatsAppTemplates extends Component
{
    public Store $store;

    public string $selected = 'standard';

    public function mount(Store $store): void
    {
        abort_unless($store->user_id === auth()->id(), 403);

        $this->store = $store;

        // Pre-select whatever the store already has configured
        $existing = $store->credentials?->whatsapp_confirmation_tier;

        if ($existing && in_array($existing, MessageTemplates::keys(), true)) {
            $this->selected = $existing;
        }
    }

    public function select(string $key): void
    {
        if (! in_array($key, MessageTemplates::keys(), true)) {
            return;
        }

        $this->selected = $key;
    }

    public function save(): void
    {
        $this->store->credentials()->updateOrCreate(
            ['store_id' => $this->store->id],
            ['whatsapp_confirmation_tier' => $this->selected]
        );

        $this->dispatch('notify', type: 'success', message: 'Template saved.');
    }

    #[Computed]
    public function preview(): string
    {
        return MessageTemplates::renderPreview($this->selected);
    }

    #[Computed]
    public function buttons(): array
    {
        return MessageTemplates::get($this->selected)['buttons'] ?? [];
    }

    public function render(): View
    {
        return view('livewire.stores.settings.whatsapp-templates', [
            'templates' => MessageTemplates::all(),
        ]);
    }
}
