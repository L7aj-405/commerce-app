<?php

declare(strict_types=1);

namespace App\Services\WhatsApp;

use App\Models\Order;
use App\Models\CustomerInteraction;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class AIResponseAnalyzerService
{
    public function __construct(
        private string $provider = 'openai',
        private string $apiKey = '',
        private string $model = 'gpt-4-mini',
    ) {}

    /**
     * Analyze customer reply with AI
     */
    public function analyzeCustomerResponse(Order $order, string $rawReply): AnalysisResult
    {
        try {
            // Build AI prompt
            $prompt = $this->buildPrompt($order, $rawReply);

            // Call AI
            $aiResponse = $this->callAI($prompt);

            // Parse response
            $result = $this->parseAIResponse($aiResponse);

            // Log interaction
            $this->logInteraction($order, $rawReply, $result);

            return $result;

        } catch (\Exception $e) {
            Log::error('AI analysis failed, using fallback', [
                'order_id' => $order->id,
                'error' => $e->getMessage(),
            ]);

            return $this->fallbackAnalysis($rawReply);
        }
    }

    /**
     * Simple fallback if AI fails
     */
    private function fallbackAnalysis(string $reply): AnalysisResult
    {
        $reply = strtolower(trim($reply));

        // Check for confirm keywords
        if (str_contains($reply, 'yes') || str_contains($reply, 'ok') || str_contains($reply, 'confirm') || str_contains($reply, '1')) {
            return new AnalysisResult(
                intent: 'confirm',
                confidence: 0.85,
                reasoning: 'Keyword matching: found confirm keyword',
            );
        }

        // Check for cancel keywords
        if (str_contains($reply, 'no') || str_contains($reply, 'cancel') || str_contains($reply, 'not') || str_contains($reply, '0')) {
            return new AnalysisResult(
                intent: 'cancel',
                confidence: 0.85,
                reasoning: 'Keyword matching: found cancel keyword',
            );
        }

        // Check for question keywords
        if (str_contains($reply, '?') || str_contains($reply, 'when') || str_contains($reply, 'how') || str_contains($reply, 'where')) {
            return new AnalysisResult(
                intent: 'question',
                confidence: 0.80,
                reasoning: 'Detected question',
                requiresHumanReview: true,
            );
        }

        // Can't determine
        return new AnalysisResult(
            intent: 'unclear',
            confidence: 0.40,
            reasoning: 'Could not determine intent',
            requiresHumanReview: true,
        );
    }

    /**
     * Build prompt for AI
     */
    private function buildPrompt(Order $order, string $rawReply): string
    {
        return <<<PROMPT
Analyze this customer reply to an order confirmation request.

Order Details:
- Order #: {$order->order_number}
- Total: {$order->total} {$order->currency}
- Customer: {$order->customer_name}

Customer Reply: "$rawReply"

Determine the customer's intent. Respond with ONLY valid JSON (no markdown, no explanation):
{
  "intent": "confirm" | "cancel" | "question" | "unclear",
  "confidence": 0.0-1.0,
  "reasoning": "brief explanation"
}

Intent meanings:
- "confirm": Customer wants to proceed
- "cancel": Customer wants to cancel
- "question": Customer has a question
- "unclear": You cannot determine intent
PROMPT;
    }

    /**
     * Call AI API
     */
    private function callAI(string $prompt): string
    {
        if ($this->provider === 'openai') {
            return $this->callOpenAI($prompt);
        }

        if ($this->provider === 'anthropic') {
            return $this->callAnthropic($prompt);
        }

        throw new \Exception("Unknown AI provider: {$this->provider}");
    }

    /**
     * Call OpenAI API
     */
    private function callOpenAI(string $prompt): string
    {
        $response = Http::withToken($this->apiKey)
            ->post('https://api.openai.com/v1/chat/completions', [
                'model' => $this->model,
                'messages' => [
                    ['role' => 'system', 'content' => 'You are an expert at understanding customer intent. Always respond with valid JSON only.'],
                    ['role' => 'user', 'content' => $prompt],
                ],
                'temperature' => 0.3,
                'max_tokens' => 500,
            ]);

        if (!$response->successful()) {
            throw new \Exception("OpenAI API error: {$response->body()}");
        }

        return $response->json('choices.0.message.content', '');
    }

    /**
     * Call Anthropic API
     */
    private function callAnthropic(string $prompt): string
    {
        $response = Http::withHeaders(['x-api-key' => $this->apiKey])
            ->post('https://api.anthropic.com/v1/messages', [
                'model' => $this->model,
                'max_tokens' => 500,
                'messages' => [
                    ['role' => 'user', 'content' => $prompt],
                ],
                'system' => 'You are an expert at understanding customer intent. Always respond with valid JSON only.',
            ]);

        if (!$response->successful()) {
            throw new \Exception("Anthropic API error: {$response->body()}");
        }

        return $response->json('content.0.text', '');
    }

    /**
     * Parse AI response into AnalysisResult
     */
    private function parseAIResponse(string $response): AnalysisResult
    {
        // Remove markdown if present
        $clean = preg_replace('/```json\n?|\n?```/', '', $response);
        $data = json_decode($clean, true);

        if (!$data || !isset($data['intent'])) {
            throw new \Exception('Invalid AI response format');
        }

        return new AnalysisResult(
            intent: $data['intent'] ?? 'unclear',
            confidence: (float) ($data['confidence'] ?? 0),
            reasoning: $data['reasoning'] ?? '',
            suggestedAction: $data['suggestedAction'] ?? null,
            suggestedMessage: $data['suggestedMessage'] ?? null,
            requiresHumanReview: $data['requiresHumanReview'] ?? false,
            languageDetected: $data['languageDetected'] ?? 'en',
        );
    }

    /**
     * Log customer interaction
     */
    private function logInteraction(Order $order, string $rawReply, AnalysisResult $result): void
    {
        // Add to order interactions
        $interactions = $order->whatsapp_interactions ?? [];
        $interactions[] = [
            'type' => 'received',
            'message' => $rawReply,
            'timestamp' => now()->toIso8601String(),
            'ai_analysis' => $result->toArray(),
        ];

        $order->update(['whatsapp_interactions' => $interactions]);

        // Create detailed log
        CustomerInteraction::create([
            'order_id' => $order->id,
            'customer_phone' => $order->customer_phone,
            'interaction_type' => 'whatsapp_received',
            'raw_message' => $rawReply,
            'ai_analysis' => $result->toArray(),
            'detected_intent' => $result->intent,
            'confidence' => $result->confidence,
            'action_taken' => $result->suggestedAction,
        ]);
    }
}

/**
 * Value Object for AI Analysis Result
 */
class AnalysisResult
{
    public function __construct(
        public string $intent,
        public float $confidence,
        public string $reasoning = '',
        public ?string $suggestedAction = null,
        public ?string $suggestedMessage = null,
        public bool $requiresHumanReview = false,
        public string $languageDetected = 'en',
    ) {}

    public function toArray(): array
    {
        return [
            'intent' => $this->intent,
            'confidence' => $this->confidence,
            'reasoning' => $this->reasoning,
            'suggestedAction' => $this->suggestedAction,
            'suggestedMessage' => $this->suggestedMessage,
            'requiresHumanReview' => $this->requiresHumanReview,
            'languageDetected' => $this->languageDetected,
        ];
    }

    public function isHighConfidence(): bool
    {
        return $this->confidence >= 0.85;
    }

    public function isLowConfidence(): bool
    {
        return $this->confidence < 0.60;
    }
}