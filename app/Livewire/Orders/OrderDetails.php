<?php

declare(strict_types=1);

namespace App\Livewire\Orders;

use App\Models\Order;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Gate;
use Livewire\Attributes\Layout;
use Livewire\Component;

#[Layout('layouts.app')]
class OrderDetails extends Component
{
    public Order $order;

    public function mount(Order $order): void
    {
        Gate::authorize('view', $order);
        $this->order = $order;
    }

    public function markAsConfirmed(): void
    {
        Gate::authorize('update', $this->order);
        $this->order->markAsConfirmed();
        $this->order = $this->order->fresh();
        session()->flash('success', 'Order confirmed.');
    }

    public function markAsCancelled(): void
    {
        Gate::authorize('update', $this->order);
        $this->order->markAsCancelled();
        $this->order = $this->order->fresh();
        session()->flash('success', 'Order cancelled.');
    }

    public function sendWhatsApp(): void
    {
        Gate::authorize('update', $this->order);
        // TODO: Phase 3 — dispatch WhatsApp confirmation via WhatsAppService.
        session()->flash('info', 'WhatsApp feature coming soon.');
    }

    public function resync(): void
    {
        Gate::authorize('update', $this->order);
        // TODO: Phase 3 — re-fetch order from platform connector and update.
        session()->flash('info', 'Resync feature coming soon.');
    }

    public function render(): View
    {
        return view('livewire.orders.order-details', [
            'order' => $this->order->load(['store', 'platformConnection']),
        ]);
    }
}
