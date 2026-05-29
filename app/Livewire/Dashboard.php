<?php

declare(strict_types=1);

namespace App\Livewire;

use App\Models\Store;
use App\Models\Order;
use Livewire\Component;
use Livewire\Attributes\Layout;
use Illuminate\Support\Facades\Auth;

#[Layout('layouts.app')]
class Dashboard extends Component
{
    public ?Store $store = null;

    public array $stats = [
        'total_sales' => ['value' => 0, 'currency' => 'USD', 'orders_count' => 0, 'change' => '+0%', 'period' => ''],
        'visitors'    => ['value' => 0, 'avg_time' => '0:00m', 'change' => '+0%', 'period' => ''],
        'refunds'     => ['value' => 0, 'disputed' => 0, 'change' => '+0%', 'period' => ''],
    ];

    public function mount(): void
    {
        $user = Auth::user();
        $this->store = $user->stores()->first();

        if ($this->store === null) {
            return;
        }

        $this->loadStats();
    }

    private function loadStats(): void
    {
        $orders = Order::where('store_id', $this->store->id)
            ->where('created_at', '>=', now()->subDays(7))
            ->get();

        $this->stats = [
            'total_sales' => [
                'value' => $orders->sum('total'),
                'currency' => 'USD',
                'orders_count' => $orders->count(),
                'change' => '+15.6%',
                'period' => '+1.4k this week',
            ],
            'visitors' => [
                'value' => 12302,
                'avg_time' => '4:30m',
                'change' => '+12.7%',
                'period' => '+1.2k this week',
            ],
            'refunds' => [
                'value' => 963,
                'disputed' => 2,
                'change' => '-12.7%',
                'period' => '-213',
            ],
        ];
    }

    public function render()
    {
        return view('livewire.dashboard');
    }
}