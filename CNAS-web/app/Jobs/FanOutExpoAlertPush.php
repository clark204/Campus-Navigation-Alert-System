<?php

namespace App\Jobs;

use App\Enums\AlertResolution;
use App\Models\Alert;
use App\Models\DevicePushToken;
use App\Services\ExpoPush;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Collection;

/**
 * Phone push for an alert (sent, level changed, all clear or false alarm): one SendExpoAlertPush job per 100 app
 * tokens, so a failure retries only its own batch and no phone gets the alert twice (deploy-review #15).
 */
class FanOutExpoAlertPush implements ShouldQueue
{
    use Queueable;

    public function __construct(public Alert $alert, public ?AlertResolution $resolution = null) {}

    public function handle(): void
    {
        DevicePushToken::query()->select('id')->chunkById(ExpoPush::BATCH, function (Collection $tokens) {
            SendExpoAlertPush::dispatch($this->alert, $this->resolution, $tokens->pluck('id')->all());
        });
    }
}
