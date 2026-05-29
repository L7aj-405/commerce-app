<?php

declare(strict_types=1);

use App\Http\Controllers\Api\WhatsAppWebhookController;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

Route::middleware('auth:sanctum')->get('/user', function (Request $request) {
    return $request->user();
});

Route::prefix('webhooks/whatsapp')->group(function () {
    // Meta webhook verification (GET hub challenge)
    Route::get('/', [WhatsAppWebhookController::class, 'verify']);

    // Meta webhook messages
    Route::post('/', [WhatsAppWebhookController::class, 'handle']);

    // Health check
    Route::get('/health', [WhatsAppWebhookController::class, 'health']);
});
