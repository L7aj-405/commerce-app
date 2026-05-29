<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Models\Order;
use App\Services\WhatsApp\WhatsAppMessageService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class SendWhatsAppConfirmation implements ShouldQueue
{
    use Queueable, InteractsWithQueue, SerializesModels;

    public int $tries = 3;

    public array $backoff = [30, 120, 300];

    public function __construct(
        public readonly Order $order,
    ) {}

    public function handle(WhatsAppMessageService $whatsapp): void
    {
        $this->order->refresh();

        if ($this->order->whatsapp_message_sent) {
            return;
        }

        $whatsapp->sendOrderConfirmation($this->order);
    }

    public function failed(\Throwable $e): void
    {
        Log::error('SendWhatsAppConfirmation job failed permanently', [
            'order_id' => $this->order->id,
            'error'    => $e->getMessage(),
        ]);
    }
}
