<?php

use App\Models\EmailCode;
use App\Models\LoginActivity;
use App\Models\RegistrationDraft;
use App\Models\SafetyCheck;
use Illuminate\Foundation\Inspiring;
use Illuminate\Notifications\DatabaseNotification;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// Drafts expire after 30 minutes; this deletes them. (COR PDFs are never stored.)
Schedule::command('model:prune', ['--model' => [RegistrationDraft::class]])
    ->everyFifteenMinutes()
    ->withoutOverlapping();

// Sign-in history is kept for LoginActivity::RETENTION_DAYS (90), as the register page's privacy notice says.
Schedule::command('model:prune', ['--model' => [LoginActivity::class]])
    ->daily()
    ->withoutOverlapping();

// "I'm safe" answers are deleted SafetyCheck::RETENTION_DAYS (30) after their alert is resolved (Data Privacy Act).
Schedule::command('model:prune', ['--model' => [SafetyCheck::class]])
    ->daily()
    ->withoutOverlapping();

// Expired mobile app tokens are already rejected; this removes them from the table.
Schedule::command('sanctum:prune-expired', ['--hours' => 24])->daily();

// Emailed codes work for 10 minutes (EmailCodes::TTL_MINUTES); expired ones are removed.
Schedule::command('model:prune', ['--model' => [EmailCode::class]])
    ->hourly()
    ->withoutOverlapping();

// Bell notifications (partials/notification-bell) are kept 90 days; the replies themselves stay on Help & contact.
Schedule::call(fn () => DatabaseNotification::query()->where('created_at', '<', now()->subDays(90))->delete())
    ->name('prune-notifications')
    ->daily()
    ->withoutOverlapping();
