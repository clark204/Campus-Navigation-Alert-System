<?php

namespace Tests\Feature\Schedule;

use App\Models\Room;
use App\Services\RoomMatcher;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\TestWith;
use Tests\TestCase;

class RoomMatcherTest extends TestCase
{
    use RefreshDatabase;

    #[TestWith(['CompLab1'])]
    #[TestWith(['COMLAB 1'])]
    #[TestWith(['ComLab 1'])]
    #[TestWith(['comp-lab 1.'])]
    #[TestWith(['r6'])]
    public function test_spellings_of_a_room_match_its_name_or_code(string $roomText): void
    {
        Room::factory()->create(['code' => 'r5', 'name' => 'ComLab 2']);
        $comLab1 = Room::factory()->create(['code' => 'r6', 'name' => 'ComLab 1']);

        $this->assertTrue((new RoomMatcher)->match($roomText)?->is($comLab1));
    }

    #[TestWith(['Online'])]
    #[TestWith(['ONLINE'])]
    #[TestWith(['TBA'])]
    #[TestWith(['tba'])]
    public function test_online_and_tba_are_not_rooms_even_when_a_room_has_that_name(string $roomText): void
    {
        Room::factory()->create(['name' => $roomText]);

        $this->assertFalse(RoomMatcher::isRoom($roomText));
        $this->assertNull((new RoomMatcher)->match($roomText));
    }

    public function test_a_room_that_is_not_on_the_map_has_no_match_but_is_still_a_room(): void
    {
        Room::factory()->create(['name' => 'ComLab 1']);

        $this->assertTrue(RoomMatcher::isRoom('FIELD'));
        $this->assertNull((new RoomMatcher)->match('FIELD'));
        $this->assertNull((new RoomMatcher)->match('ComLab 11'));
    }

    public function test_a_room_added_to_the_map_later_starts_matching(): void
    {
        $this->assertNull((new RoomMatcher)->match('NEE 204'));

        $room = Room::factory()->create(['name' => 'NEE 204']);

        $this->assertTrue((new RoomMatcher)->match('NEE 204')?->is($room));
    }
}
