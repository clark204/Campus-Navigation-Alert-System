<?php

namespace App\Notifications;

use App\Models\ConcernReply;
use App\Models\User;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Str;

/**
 * The admin replied to one of the user's concerns: shows in their portal's notification bell and opens their Help &
 * contact page at that message. The reply itself is also emailed (App\Mail\ConcernReplyMail), so this is database only.
 */
class ConcernReplied extends Notification
{
    public function __construct(public ConcernReply $reply) {}

    /**
     * @return list<string>
     */
    public function via(object $notifiable): array
    {
        return ['database'];
    }

    /**
     * Every notification stores title, body and url (a path, so it survives a change of APP_URL): partials/notification-bell.
     *
     * @return array{kind: string, concern_id: int, title: string, body: string, url: string}
     */
    public function toArray(User $notifiable): array
    {
        $concern = $this->reply->concern;

        return [
            'kind' => 'concern_reply',
            'concern_id' => $concern->id,
            'title' => 'The administrator replied to your message',
            'body' => Str::limit($this->reply->message, 140),
            'url' => route($notifiable->role->portal()->value.'.help', absolute: false).'#concern-'.$concern->id,
        ];
    }
}
