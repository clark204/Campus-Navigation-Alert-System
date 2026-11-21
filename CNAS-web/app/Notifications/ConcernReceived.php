<?php

namespace App\Notifications;

use App\Models\Concern;
use App\Models\User;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Str;

/**
 * A new concern for Admin > Concerns: shows in every admin's notification bell. Marked read for everyone once an admin
 * replies to it or marks it resolved (Admin\ConcernController).
 */
class ConcernReceived extends Notification
{
    public function __construct(public Concern $concern) {}

    /**
     * @return list<string>
     */
    public function via(object $notifiable): array
    {
        return ['database'];
    }

    /**
     * @return array{kind: string, concern_id: int, title: string, body: string, url: string}
     */
    public function toArray(User $notifiable): array
    {
        $from = $this->concern->user?->full_name ?? 'A visitor';

        return [
            'kind' => 'concern',
            'concern_id' => $this->concern->id,
            'title' => "New message from {$from}",
            'body' => $this->concern->topic->shortLabel().': '.Str::limit($this->concern->message, 140),
            'url' => route('admin.concerns.show', $this->concern, absolute: false),
        ];
    }
}
