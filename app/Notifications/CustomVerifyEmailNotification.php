<?php

declare(strict_types=1);

namespace App\Notifications;

use Illuminate\Auth\Notifications\VerifyEmail;
use Illuminate\Notifications\Messages\MailMessage;

class CustomVerifyEmailNotification extends VerifyEmail
{
    public function toMail(mixed $notifiable): MailMessage
    {
        $url     = $this->verificationUrl($notifiable);
        $appName = config('app.name');

        return (new MailMessage)
            ->subject("Verify your email — {$appName}")
            ->greeting("Hello {$notifiable->name},")
            ->line('Thanks for registering! Please verify your email to get started.')
            ->action('Verify Email', $url)
            ->line('This link expires in 60 minutes.')
            ->salutation("The {$appName} Team");
    }
}
