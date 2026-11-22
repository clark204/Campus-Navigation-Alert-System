<?php

namespace App\Jobs;

use App\Enums\AlertResolution;
use App\Models\Alert;
use App\Models\DevicePushToken;
use App\Services\ExpoPush;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * One batch (up to 100) of the phone push for an alert. Tokens Expo rejects right away as DeviceNotRegistered are
 * deleted; the others' receipts are checked 15 minutes later (CheckExpoPushReceipts), as Expo recommends.
 */
class SendExpoAlertPush implements ShouldQueue
{
    use Queueable;

    /** Expo answers 429 or 5xx when busy: try again, a little later each time. Nothing was sent then. */
    public int $tries = 3;

    /** @var list<int> */
    public array $backoff = [10, 30];

    /** When Expo recommends checking receipts. */
    public const RECEIPT_DELAY_MINUTES = 15;

    /**
     * @param  list<int>  $tokenIds
     */
    public function __construct(public Alert $alert, public ?AlertResolution $resolution, public array $tokenIds) {}

    public function handle(ExpoPush $expo): void
    {
        // Tokens deleted since the fan-out (alerts turned off) are skipped.
        $tokens = DevicePushToken::whereKey($this->tokenIds)->pluck('expo_token')->values()->all();
        if ($tokens === []) {
            return;
        }

        $tickets = $expo->send(array_map(fn (string $to) => $expo->message($to, $this->alert, $this->resolution), $tokens));

        $gone = [];
        $receipts = [];
        foreach ($tickets as $index => $ticket) {
            $token = $tokens[$index] ?? null;
            if ($token === null) {
                continue;
            }
            if (($ticket['status'] ?? null) === 'ok' && isset($ticket['id'])) {
                $receipts[$ticket['id']] = $token;
            } elseif (($ticket['details']['error'] ?? null) === ExpoPush::DEVICE_NOT_REGISTERED) {
                $gone[] = $token;
            }
        }

        if ($gone !== []) {
            DevicePushToken::whereIn('expo_token', $gone)->delete();
        }

        if ($receipts !== []) {
            CheckExpoPushReceipts::dispatch($receipts)->delay(now()->addMinutes(self::RECEIPT_DELAY_MINUTES));
        }
    }
}
