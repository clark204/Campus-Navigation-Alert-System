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
        return [
            'kind' => 'concern',
            'concern_id' => $this->concern->id,
            'title' => self::title($this->concern),
            'body' => self::body($this->concern),
            'url' => route('admin.concerns.show', $this->concern, absolute: false),
        ];
    }

    /**
     * "New message from Juan Luna": the bell's title, also the admins' phone push (AdminReport).
     */
    public static function title(Concern $concern): string
    {
        return 'New message from '.($concern->user?->full_name ?? 'A visitor');
    }

    /**
     * "Map: The library is missing…"
     */
    public static function body(Concern $concern): string
    {
        return $concern->topic->shortLabel().': '.Str::limit($concern->message, 140);
    }
}
