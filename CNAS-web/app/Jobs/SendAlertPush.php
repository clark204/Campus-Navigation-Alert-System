<?php

namespace App\Jobs;

use App\Enums\AlertResolution;
use App\Models\Alert;
use App\Notifications\AlertPushNotification;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Notifications\AnonymousNotifiable;
use Illuminate\Support\Facades\Notification;
use NotificationChannels\WebPush\PushSubscription;

/**
 * Push an alert (sent, all clear or false alarm) to every subscribed device: signed-in users and anonymous guests.
 * Runs on the database queue, so `php artisan queue:work` must be running. Subscriptions the push service reports as
 * gone (404/410) are deleted by the package's report handler.
 */
class SendAlertPush implements ShouldQueue
{
    use Queueable;

    /** Subscriptions sent per batch. */
    private const CHUNK = 500;

    public int $tries = 3;

    public int $backoff = 5;

    public function __construct(public Alert $alert, public ?AlertResolution $resolution = null) {}

    public function handle(): void
    {
        $notification = new AlertPushNotification($this->alert, $this->resolution);

        // WebPushChannel reads the subscriptions from the 'WebPush' route.
        PushSubscription::query()->chunkById(self::CHUNK, function (Collection $subscriptions) use ($notification) {
            Notification::sendNow((new AnonymousNotifiable)->route('WebPush', $subscriptions), $notification);
        });
    }
}
