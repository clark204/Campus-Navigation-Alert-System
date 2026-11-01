<?php

namespace Tests\Feature;

use App\Models\RegistrationDraft;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class RegistrationDraftPruningTest extends TestCase
{
    use RefreshDatabase;

    private function draft(string $token, int $expiresInMinutes): RegistrationDraft
    {
        $draft = new RegistrationDraft;
        $draft->forceFill([
            'token_hash' => RegistrationDraft::hashToken($token),
            'data' => [],
            'expires_at' => now()->addMinutes($expiresInMinutes),
        ])->save();

        return $draft;
    }

    public function test_expired_drafts_are_deleted(): void
    {
        $expired = $this->draft('expired', -1);
        $active = $this->draft('active', 10);

        $this->artisan('model:prune', ['--model' => [RegistrationDraft::class]])->assertSuccessful();

        $this->assertModelMissing($expired);
        $this->assertModelExists($active);
    }

    public function test_pruning_is_scheduled(): void
    {
        $events = collect(app(Schedule::class)->events())
            ->filter(fn ($event) => str_contains($event->command, 'model:prune') && str_contains($event->command, 'RegistrationDraft'));

        $this->assertCount(1, $events);
    }
}
