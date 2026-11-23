<?php

namespace App\Jobs;

use App\Models\DevicePushToken;
use App\Models\SafetyCheck;
use App\Services\ExpoPush;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * "<admin> is on the way", pushed to the phones of the person who asked for help (ExpoPush::helpUpdateMessage) when
 * an admin taps "I'm responding". Their open page learns it from its help ping instead (HelpPingController).
 */
class SendHelpUpdatePush implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    /** @var list<int> */
    public array $backoff = [10, 30];

    public function __construct(public SafetyCheck $check) {}

    public function handle(ExpoPush $expo): void
    {
        $tokens = DevicePushToken::query()->where('user_id', $this->check->user_id)->pluck('expo_token')->values()->all();

        if ($tokens !== []) {
            $expo->deliver($tokens, fn (string $to) => $expo->helpUpdateMessage($to, $this->check));
        }
    }
}
