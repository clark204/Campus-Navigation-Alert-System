<?php

use App\Models\RegistrationDraft;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// Drafts expire after 30 minutes; this deletes them. (COR PDFs are never stored.)
Schedule::command('model:prune', ['--model' => [RegistrationDraft::class]])
    ->everyFifteenMinutes()
    ->withoutOverlapping();

// Expired mobile app tokens are already rejected; this removes them from the table.
Schedule::command('sanctum:prune-expired', ['--hours' => 24])->daily();
