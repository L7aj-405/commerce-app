<?php

declare(strict_types=1);

namespace App\Services\WhatsApp;

use App\Models\Order;
use Illuminate\Support\Facades\Log;

class ActionRouterService
{
    public function __construct(
        private EvolutionApiService $evolutionApi,
    ) {}

    /**
     * Route action based on AI analysis
     */
    public function routeAction(Order $order, AnalysisResult $analysis): void
    {
        Log::info('Routing action', [
            'order_id' => $order->id,
            'intent' => $analysis->intent,
            'confidence' => $analysis->confidence,
        ]);

        match ($analysis->intent) {
            'confirm' => $this->handleConfirm($order, $analysis),
            'cancel' => $this->handleCancel($order, $analysis),
            'question' => $this->handleQuestion($order, $analysis),
            'unclear' => $this->handleUnclear($order, $analysis),
            default => $this->handleUnclear($order, $analysis),
        };
    }

    /**
     * Handle: Customer confirmed order
     */
    private function handleConfirm(Order $order, AnalysisResult $analysis): void
    {
        if ($analysis->confidence < 0.85) {
            // Low confidence - ask again
            $this->sendMessage($order, "Just confirming - you want to proceed with order #{$order->order_number}? (YES/NO)");
            return;
        }

        // High confidence - auto-confirm
        $order->markAsConfirmed();

        // Send receipt
        $receipt = "✅ Order Confirmed!\n\nOrder #: {$order->order_number}\nTotal: {$order->total} {$order->currency}\n\nDelivery in ~30 minutes 🚗\n\nThank you! 🙏";
        $this->sendMessage($order, $receipt);

        Log::info('Order auto-confirmed', ['order_id' => $order->id]);
    }

    /**
     * Handle: Customer cancelled order
     */
    private function handleCancel(Order $order, AnalysisResult $analysis): void
    {
        if ($analysis->confidence < 0.85) {
            // Low confidence - ask again
            $this->sendMessage($order, "Are you sure you want to cancel? (YES/NO)");
            return;
        }

        // High confidence - auto-cancel
        $order->markAsCancelled();

        $this->sendMessage($order, "✅ Order cancelled. Refund in 2-3 days. We'd love to serve you again! 💙");

        Log::info('Order auto-cancelled', ['order_id' => $order->id]);
    }

    /**
     * Handle: Customer has a question
     */
    private function handleQuestion(Order $order, AnalysisResult $analysis): void
    {
        // Try to auto-answer
        $question = $analysis->suggestedMessage;

        if (str_contains(strtolower($question ?? ''), ['time', 'when'])) {
            $this->sendMessage($order, "Your order arrives in ~30 minutes! 🚀");
        } elseif (str_contains(strtolower($question ?? ''), ['cost', 'price', 'total'])) {
            $this->sendMessage($order, "Your total is {$order->total} {$order->currency} including delivery and taxes. ✅");
        } else {
            // Can't answer - escalate
            $this->sendMessage($order, "Let me connect you with our team... 📞");
            Log::warning('Order needs manual assistance', ['order_id' => $order->id, 'question' => $question]);
        }
    }

    /**
     * Handle: Can't determine intent
     */
    private function handleUnclear(Order $order, AnalysisResult $analysis): void
    {
        $this->sendMessage($order, "Sorry, I didn't understand. 😅\n\nPlease confirm:\n- YES to confirm\n- NO to cancel");

        if ($analysis->requiresHumanReview) {
            Log::warning('Order needs manual review', ['order_id' => $order->id, 'reason' => $analysis->reasoning]);
        }
    }

    /**
     * Send message helper
     */
    private function sendMessage(Order $order, string $message): void
    {
        $this->evolutionApi->sendMessage($order->customer_phone, $message, $order->id);

        // Log sent message
        $interactions = $order->whatsapp_interactions ?? [];
        $interactions[] = [
            'type' => 'sent',
            'message' => $message,
            'timestamp' => now()->toIso8601String(),
        ];

        $order->update(['whatsapp_interactions' => $interactions]);
    }
}