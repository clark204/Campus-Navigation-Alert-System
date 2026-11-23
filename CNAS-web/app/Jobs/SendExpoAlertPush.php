<?php

namespace App\Jobs;

use App\Enums\AlertResolution;
use App\Models\Alert;
use App\Models\DevicePushToken;
use App\Services\ExpoPush;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * One batch (up to 100) of the phone push for an alert. ExpoPush::deliver looks after the tokens (dead ones deleted,
 * receipts checked 15 minutes later).
 */
class SendExpoAlertPush implements ShouldQueue
{
    use Queueable;

    /** Expo answers 429 or 5xx when busy: try again, a little later each time. Nothing was sent then. */
    public int $tries = 3;

    /** @var list<int> */
    public array $backoff = [10, 30];

    /**
     * @param  list<int>  $tokenIds
     */
    public function __construct(public Alert $alert, public ?AlertResolution $resolution, public array $tokenIds) {}

    public function handle(ExpoPush $expo): void
    {
        // Tokens deleted since the fan-out (alerts turned off) are skipped.
        $tokens = DevicePushToken::whereKey($this->tokenIds)->pluck('expo_token')->values()->all();

        $expo->deliver($tokens, fn (string $to) => $expo->message($to, $this->alert, $this->resolution));
    }
}
