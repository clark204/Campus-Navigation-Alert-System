<?php

namespace App\Jobs;

use App\Enums\UserRole;
use App\Models\DevicePushToken;
use App\Services\AdminReport;
use App\Services\ExpoPush;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Collection;

/**
 * A report (AdminReport: a new concern, "I need help") pushed to every phone signed in to an admin account, and to no
 * one else. Queued like the alert push; Expo's busy answers are retried, and ExpoPush::deliver looks after the tokens.
 */
class SendAdminReportPush implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    /** @var list<int> */
    public array $backoff = [10, 30];

    public function __construct(public AdminReport $report) {}

    public function handle(ExpoPush $expo): void
    {
        DevicePushToken::query()
            ->whereHas('user', fn ($users) => $users->where('role', UserRole::Admin))
            ->select(['id', 'expo_token'])
            ->chunkById(ExpoPush::BATCH, function (Collection $tokens) use ($expo) {
                $expo->deliver($tokens->pluck('expo_token')->values()->all(), fn (string $to) => $expo->reportMessage($to, $this->report));
            });
    }
}
