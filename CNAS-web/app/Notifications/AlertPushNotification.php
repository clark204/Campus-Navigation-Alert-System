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

        $message = (new WebPushMessage)
            // One notification per alert: the all clear replaces the emergency on the lock screen.
            ->tag(self::tag($alert))
            ->renotify()
            ->icon(asset('icons/icon-192.png'))
            ->badge(asset('icons/badge-96.png'))
            ->options(['TTL' => self::TIME_TO_LIVE, 'urgency' => 'high'])
            ->title(self::title($alert, $this->resolution))
            ->body(self::body($alert, $this->resolution));

        if ($this->resolution === null) {
            $message->data(['url' => route('navigate', ['alert' => $alert->id]), 'alert_id' => $alert->id]);

            // Only an emergency waits for a tap and buzzes. An advisory or a warning is an ordinary notification.
            return $alert->isEmergency() ? $message->requireInteraction()->vibrate(self::VIBRATE) : $message;
        }

        return $message->data(['url' => url('/'), 'alert_id' => $alert->id]);
    }

    /**
     * One notification per alert on a device: a resolution or a level change replaces the earlier one. The phone
     * push (SendExpoAlertPush) uses the same id.
     */
    public static function tag(Alert $alert): string
    {
        return "cnas-alert-{$alert->id}";
    }

    /**
     * The kind and the alert's short title: "EMERGENCY · FIRE: Fire on campus", "DRILL · FIRE: …"; once it is over
     * "ALL CLEAR · FIRE: …" or "DRILL OVER · FIRE: …". Shared with the phone push.
     */
    public static function title(Alert $alert, ?AlertResolution $resolution = null): string
    {
        return self::kind($alert, $resolution).": {$alert->title}";
    }

    /**
     * "EMERGENCY · FIRE", "DRILL · FIRE", "ALL CLEAR · FIRE", "DRILL OVER · FIRE".
     */
    public static function kind(Alert $alert, ?AlertResolution $resolution = null): string
    {
        $type = Str::upper($alert->type->label());

        if ($resolution === null) {
            return ($alert->is_drill ? 'DRILL' : Str::upper($alert->severity->label()))." · {$type}";
        }

        return ($alert->is_drill ? 'DRILL OVER' : Str::upper($resolution->label()))." · {$type}";
    }

    /**
     * Where, and "What to do now" (or what is happening when there is none); or the admin's closing message. Shared
     * with the phone push.
     */
    public static function body(Alert $alert, ?AlertResolution $resolution = null): string
    {
        if ($resolution !== null) {
            return Str::limit((string) $alert->resolved_message, self::BODY_LIMIT);
        }

        $action = $alert->whatToDo() ?: Str::of($alert->message)->explode("\n")->first();

        return Str::limit("{$alert->locationLabel()}: {$action}", self::BODY_LIMIT);
    }
}
