<?php

declare(strict_types=1);

namespace App\Mail;

use App\Models\Store;
use App\Models\StoreInvitation;
use App\Models\User;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class InvitationMail extends Mailable implements ShouldQueue
{
    use Queueable, SerializesModels;

    public function __construct(
        public StoreInvitation $invitation,
        public Store $store,
        public User $invitedBy,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: "You're invited to join {$this->store->name} on SaaS Commerce",
        );
    }

    public function content(): Content
    {
        return new Content(
            view: 'emails.invitation',
            with: [
                'store'        => $this->store,
                'invitation'   => $this->invitation,
                'invitedBy'    => $this->invitedBy,
                'acceptUrl'    => url(route('invitation.show', $this->invitation->token, absolute: false)),
                'expiresAt'    => $this->invitation->expires_at,
                'roleLabel'    => $this->invitation->storeRole?->name ?? 'Team member',
            ],
        );
    }
}
