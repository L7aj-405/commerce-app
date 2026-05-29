<?php

declare(strict_types=1);

namespace App\Services\Meta;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class MetaOAuthService
{
    private string $appId;
    private string $appSecret;
    private string $redirectUri;

    public function __construct()
    {
        $this->appId = config('meta.app_id');
        $this->appSecret = config('meta.app_secret');
        $this->redirectUri = config('meta.redirect_uri');
    }

    /**
     * Generate the login URL for merchant
     */
    public function getLoginUrl(string $storeId): string
    {
        $scopes = [
            'whatsapp_business_messaging',
            'whatsapp_business_management',
        ];

        $params = [
            'client_id' => $this->appId,
            'redirect_uri' => $this->redirectUri,
            'scope' => implode(',', $scopes),
            'response_type' => 'code',
            'state' => encrypt($storeId), // Encrypt store ID for security
        ];

        return 'https://www.facebook.com/v18.0/dialog/oauth?' . http_build_query($params);
    }

    /**
     * Exchange authorization code for access token
     */
    public function exchangeCodeForToken(string $code): array
    {
        $response = Http::post('https://graph.instagram.com/v18.0/oauth/access_token', [
            'client_id' => $this->appId,
            'client_secret' => $this->appSecret,
            'redirect_uri' => $this->redirectUri,
            'code' => $code,
        ]);

        if (!$response->successful()) {
            throw new \Exception('Failed to exchange code: ' . $response->body());
        }

        return $response->json();
    }

    /**
     * Get user's WhatsApp Business Account info
     */
    public function getWhatsAppBusinessAccounts(string $accessToken): array
    {
        $response = Http::withToken($accessToken)
            ->get('https://graph.instagram.com/v18.0/me/businesses');

        if (!$response->successful()) {
            throw new \Exception('Failed to get businesses: ' . $response->body());
        }

        return $response->json('data', []);
    }

    /**
     * Get phone numbers for a WhatsApp Business Account
     */
    public function getPhoneNumbers(string $accessToken, string $wabaId): array
    {
        $response = Http::withToken($accessToken)
            ->get("https://graph.instagram.com/v18.0/{$wabaId}/phone_numbers");

        if (!$response->successful()) {
            throw new \Exception('Failed to get phone numbers: ' . $response->body());
        }

        return $response->json('data', []);
    }

    /**
     * Verify token is still valid
     */
    public function verifyToken(string $accessToken): bool
    {
        try {
            $response = Http::withToken($accessToken)
                ->get('https://graph.instagram.com/v18.0/me');

            return $response->successful();
        } catch (\Exception) {
            return false;
        }
    }
}