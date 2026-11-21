<?php

namespace App\Notifications;

use App\Enums\AlertResolution;
use App\Models\Alert;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Str;
use NotificationChannels\WebPush\WebPushChannel;
use NotificationChannels\WebPush\WebPushMessage;

/**
 * The browser push for an alert: sent ($resolution null), all clear or false alarm. public/sw.js shows it.
 * Sent through SendAlertPush to every subscription, guests included, so it never names the user.
 */
class AlertPushNotification extends Notification
{
    /** Same pattern as the takeover (resources/js/alerts/alarm.js). */
    public const VIBRATE = [500, 200, 500, 200, 500, 200, 500];

    /** Push services drop the message if the device stays offline longer than this (seconds). */
    private const TIME_TO_LIVE = 3600;

    private const BODY_LIMIT = 160;

    public function __construct(public Alert $alert, public ?AlertResolution $resolution = null) {}

    /**
     * @return list<class-string>
     */
    public function via(object $notifiable): array
    {
        return [WebPushChannel::class];
    }

    public function toWebPush(object $notifiable, Notification $notification): WebPushMessage
    {
        $alert = $this->alert;
        $type = Str::upper($alert->type->label());

        $message = (new WebPushMessage)
            // One notification per alert: the all clear replaces the emergency on the lock screen.
            ->tag("cnas-alert-{$alert->id}")
            ->renotify()
            ->icon(asset('icons/icon-192.png'))
            ->badge(asset('icons/badge-96.png'))
            ->options(['TTL' => self::TIME_TO_LIVE, 'urgency' => 'high']);

        if ($this->resolution === null) {
            $firstLine = Str::of($alert->instructions ?: $alert->message)->explode("\n")->first();

            $message
                ->title(($alert->is_drill ? 'DRILL' : Str::upper($alert->severity->label()))." · {$type}")
                ->body(Str::limit("{$alert->locationLabel()}: {$firstLine}", self::BODY_LIMIT))
                ->data(['url' => route('navigate', ['alert' => $alert->id]), 'alert_id' => $alert->id]);

            // Only an emergency waits for a tap and buzzes. An advisory or a warning is an ordinary notification.
            return $alert->isEmergency() ? $message->requireInteraction()->vibrate(self::VIBRATE) : $message;
        }

        $heading = $alert->is_drill ? 'DRILL OVER' : Str::upper($this->resolution->label());

        return $message
            ->title("{$heading} · {$type}")
            ->body(Str::limit((string) $alert->resolved_message, self::BODY_LIMIT))
            ->data(['url' => url('/'), 'alert_id' => $alert->id]);
    }
}
