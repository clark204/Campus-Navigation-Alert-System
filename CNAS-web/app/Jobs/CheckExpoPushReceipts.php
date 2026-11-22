<?php

namespace App\Jobs;

use App\Models\DevicePushToken;
use App\Services\ExpoPush;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * Expo's receipts for one batch of the phone push: a token reported as DeviceNotRegistered (app uninstalled, alerts
 * blocked) is deleted, so later alerts don't go to it. Other receipt errors are left alone.
 */
class CheckExpoPushReceipts implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    /** @var list<int> */
    public array $backoff = [60, 300];

    /**
     * @param  array<string, string>  $tokensByReceipt  Expo ticket id => the expo_token it was sent to.
     */
    public function __construct(public array $tokensByReceipt) {}

    public function handle(ExpoPush $expo): void
    {
        $gone = [];
        foreach ($expo->receipts(array_keys($this->tokensByReceipt)) as $id => $receipt) {
            if (($receipt['details']['error'] ?? null) === ExpoPush::DEVICE_NOT_REGISTERED && isset($this->tokensByReceipt[$id])) {
                $gone[] = $this->tokensByReceipt[$id];
            }
        }

        if ($gone !== []) {
            DevicePushToken::whereIn('expo_token', $gone)->delete();
        }
    }
}
