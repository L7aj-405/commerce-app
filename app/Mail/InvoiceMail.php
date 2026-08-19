<?php

declare(strict_types=1);

namespace App\Mail;

use App\Models\Facture;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Attachment;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class InvoiceMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(public readonly Facture $facture)
    {
    }

    public function envelope(): Envelope
    {
        return new Envelope(subject: "Invoice {$this->facture->invoice_number}");
    }

    public function content(): Content
    {
        return new Content(view: 'emails.invoice', with: ['facture' => $this->facture]);
    }

    /** @return array<int, Attachment> */
    public function attachments(): array
    {
        if ($this->facture->pdf_path === null) {
            return [];
        }

        return [
            Attachment::fromStorageDisk('local', $this->facture->pdf_path)
                ->as("{$this->facture->invoice_number}.pdf")
                ->withMime('application/pdf'),
        ];
    }
}
