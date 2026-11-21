<?php

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\TestWith;
use Tests\TestCase;

/**
 * Signed-in requests update users.last_seen_at at most once a minute; "online" means seen in the last 5 minutes.
 */
class TrackLastSeenTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_signed_in_web_request_records_when_the_user_was_seen(): void
    {
        $student = User::factory()->create(['role' => UserRole::Student]);
        $this->freezeSecond();

        $this->actingAs($student)->get(route('student.dashboard'))->assertOk();

        $this->assertTrue($student->fresh()->last_seen_at->eq(now()));
    }

    public function test_a_signed_in_app_request_records_when_the_user_was_seen(): void
    {
        $student = User::factory()->create(['role' => UserRole::Student]);
        Sanctum::actingAs($student);
        $this->freezeSecond();

        $this->getJson(route('api.me'))->assertOk();

        $this->assertTrue($student->fresh()->last_seen_at->eq(now()));
    }

    public function test_last_seen_is_written_at_most_once_a_minute(): void
    {
        $student = User::factory()->create(['role' => UserRole::Student]);
        $this->freezeSecond();
        $this->actingAs($student)->get(route('student.dashboard'));
        $firstSeen = now()->copy();

        $this->travel(59)->seconds();
        $this->actingAs($student->fresh())->get(route('student.dashboard'));
        $this->assertTrue($student->fresh()->last_seen_at->eq($firstSeen), 'Not written again within a minute.');

        $this->travel(1)->seconds();
        $this->actingAs($student->fresh())->get(route('student.dashboard'));
        $this->assertTrue($student->fresh()->last_seen_at->eq(now()), 'Written again once a minute has passed.');
    }

    public function test_seeing_a_user_does_not_change_when_their_account_was_updated(): void
    {
        $student = User::factory()->create(['role' => UserRole::Student, 'updated_at' => now()->subDay()]);

        $this->actingAs($student)->get(route('student.dashboard'));

        $this->assertTrue($student->fresh()->updated_at->eq($student->updated_at));
    }

    public function test_guest_requests_record_nothing(): void
    {
        $this->get(route('landing'))->assertOk();

        $this->assertDatabaseCount('users', 0);
    }

    #[TestWith([4, true])]
    #[TestWith([5, true])]
    #[TestWith([6, false])]
    public function test_a_user_is_online_when_seen_in_the_last_five_minutes(int $minutesAgo, bool $online): void
    {
        $this->freezeSecond();
        $user = User::factory()->create();
        $user->forceFill(['last_seen_at' => now()->subMinutes($minutesAgo)])->save();

        $this->assertSame($online, $user->isOnline());
        $this->assertSame($online, User::online()->whereKey($user->id)->exists());
    }

    public function test_a_user_who_was_never_seen_is_offline(): void
    {
        $user = User::factory()->create();

        $this->assertFalse($user->isOnline());
        $this->assertFalse(User::online()->exists());
    }
}
