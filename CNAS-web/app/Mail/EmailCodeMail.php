<?php

namespace App\Mail;

use App\Enums\EmailCodePurpose;
use App\Services\EmailCodes;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/**
 * A 6-digit code from App\Services\EmailCodes, sent through the queue with the MAIL_* settings in .env.
 * The plain code only exists in this message (and its queued job until it is sent); the database keeps a hash.
 */
class EmailCodeMail extends Mailable implements ShouldQueue
{
    use Queueable, SerializesModels;

    public function __construct(public string $code, public EmailCodePurpose $purpose) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: $this->purpose->subject());
    }

    public function content(): Content
    {
        return new Content(
            view: 'mail.email-code',
            text: 'mail.email-code-text',
            with: ['minutes' => EmailCodes::TTL_MINUTES],
        );
    }
}
