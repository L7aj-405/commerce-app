<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class StoreCredential extends Model
{
    use BelongsToTenant, HasUlids;

    protected $fillable = [
        'store_id',
        'whatsapp_phone_number_id',
        'whatsapp_access_token',
        'whatsapp_webhook_verify_token',
        'whatsapp_business_account_id',
        'smtp_host',
        'smtp_port',
        'smtp_username',
        'smtp_password',
        'smtp_from_name',
        'smtp_from_email',
        'whatsapp_evolution_instance_name',
        'whatsapp_evolution_api_key',
        'whatsapp_evolution_phone_number',
        'whatsapp_evolution_connected_at',
        'whatsapp_evolution_is_active',
        'whatsapp_ai_provider',
        'whatsapp_openai_api_key',
        'whatsapp_anthropic_api_key',
        'whatsapp_ai_model',
        'whatsapp_ai_enabled',
        'whatsapp_ai_tested_at',
        'whatsapp_confirmation_tier',
        'whatsapp_auto_confirm_threshold',
        'whatsapp_auto_confirm_enabled',
        'whatsapp_brand_tone',
        'whatsapp_retry_first_delay',
        'whatsapp_retry_second_delay',
        'whatsapp_max_retries',
        'whatsapp_send_discount_on_retry',
        'whatsapp_alert_on_low_confidence',
        'whatsapp_alert_on_complaint',
        'whatsapp_alert_on_cancellation',
        'whatsapp_alert_method',
        'whatsapp_support_languages',
        'whatsapp_setup_status',
        'whatsapp_setup_error',
        'whatsapp_setup_completed_at',
    ];

    protected function casts(): array
    {
        return [
            'whatsapp_access_token'           => 'encrypted',
            'whatsapp_webhook_verify_token'   => 'encrypted',
            'whatsapp_evolution_api_key'      => 'encrypted',
            'whatsapp_openai_api_key'         => 'encrypted',
            'whatsapp_anthropic_api_key'      => 'encrypted',
            'smtp_password'                   => 'encrypted',
            'smtp_port'                       => 'integer',
            'whatsapp_retry_first_delay'      => 'integer',
            'whatsapp_retry_second_delay'     => 'integer',
            'whatsapp_max_retries'            => 'integer',
            'whatsapp_auto_confirm_threshold' => 'decimal:2',
            'whatsapp_evolution_is_active'    => 'boolean',
            'whatsapp_ai_enabled'             => 'boolean',
            'whatsapp_auto_confirm_enabled'   => 'boolean',
            'whatsapp_send_discount_on_retry' => 'boolean',
            'whatsapp_alert_on_low_confidence'=> 'boolean',
            'whatsapp_alert_on_complaint'     => 'boolean',
            'whatsapp_alert_on_cancellation'  => 'boolean',
            'whatsapp_support_languages'      => 'array',
            'whatsapp_evolution_connected_at' => 'datetime',
            'whatsapp_ai_tested_at'           => 'datetime',
            'whatsapp_setup_completed_at'     => 'datetime',
        ];
    }

    public function store(): BelongsTo
    {
        return $this->belongsTo(Store::class);
    }

    public function hasWhatsapp(): bool
    {
        return filled($this->whatsapp_phone_number_id)
            && filled($this->whatsapp_access_token);
    }

    public function isWhatsAppConfigured(): bool
    {
        return filled($this->whatsapp_evolution_instance_name)
            && filled($this->whatsapp_evolution_api_key)
            && (bool) $this->whatsapp_evolution_is_active;
    }

    public function getAiProvider(): string
    {
        return $this->whatsapp_ai_provider ?? 'openai';
    }

    public function hasSmtp(): bool
    {
        return filled($this->smtp_host)
            && filled($this->smtp_username)
            && filled($this->smtp_password);
    }
}
