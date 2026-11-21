<?php

namespace App\Mail;

use App\Models\ConcernReply;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/**
 * The admin's reply to a concern (Admin > Concerns), sent to the concern's email: the reply, then the message it
 * answers. Signed-in senders also see it on Help & contact and in their notification bell (ConcernReplied).
 */
class ConcernReplyMail extends Mailable implements ShouldQueue
{
    use Queueable, SerializesModels;

    public function __construct(public ConcernReply $reply) {}

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: 'Re: your TCC NAV message ('.$this->reply->concern->topic->shortLabel().')',
        );
    }

    public function content(): Content
    {
        $concern = $this->reply->concern;

        return new Content(
            view: 'mail.concern-reply',
            text: 'mail.concern-reply-text',
            with: [
                'concern' => $concern,
                // Signed-in senders can read the conversation on their portal's Help & contact page.
                'helpUrl' => $concern->user ? route($concern->user->role->portal()->value.'.help').'#concern-'.$concern->id : null,
                'sentOn' => $concern->created_at->timezone('Asia/Manila')->format('M j, Y'),
            ],
        );
    }
}
