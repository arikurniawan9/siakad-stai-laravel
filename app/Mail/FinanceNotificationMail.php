<?php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

final class FinanceNotificationMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(
        public readonly string $mailSubject,
        public readonly string $messageContent,
        public readonly array $details,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: $this->mailSubject);
    }

    public function content(): Content
    {
        return new Content(view: 'mail.finance-notification');
    }
}
