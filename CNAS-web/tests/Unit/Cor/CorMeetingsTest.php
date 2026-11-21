<?php

namespace Tests\Unit\Cor;

use App\Enums\Weekday;
use App\Services\Cor\CorExtractor;
use Database\Seeders\CollegeSeeder;
use Database\Seeders\ProgramSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\CorFixture;
use Tests\TestCase;

/**
 * The class meetings read from a COR. Uses the framework TestCase because the program lookup reads the programs table.
 */
class CorMeetingsTest extends TestCase
{
    use RefreshDatabase;

    private const CURRENT_MEETINGS = [
        'IT 119 BSIT3B thu 16:00-19:00 CompLab1',
        'IT 119 BSIT3B tue 15:00-17:00 Online',
        'IT 120 BSIT3B fri 07:00-10:00 Online',
        'IT 121 BSIT3B sat 10:00-13:00 CompLab4',
        'IT 121 BSIT3B tue 17:30-19:30 IT105',
        'IT 122 BSIT3B thu 07:00-10:00 CompLab3',
        'IT 122 BSIT3B tue 07:00-09:00 Online',
        'IT Elec 2 BSIT3B sat 07:00-10:00 TBA',
        'IT Elec 2 BSIT3B mon 17:30-19:30 Online',
        'GEC 5 BSIT3B mon 14:30-16:00 IT 204',
        'GEC 5 BSIT3B wed 14:30-16:00 IT 204',
        'GEC 7 BSIT3B tue 13:00-14:30 IT105',
        'GEC 7 BSIT3B thu 13:00-14:30 IT105',
        'GEC 9 BSIT3B sat 13:00-16:00 IT105',
        'GEE 9 BSIT3B tue 17:00-20:00 Online',
    ];

    private const OLDER_MEETINGS = [
        'IT 112 BSIT 2B thu 16:00-19:00 CompLab2',
        'IT 112 BSIT 2B tue 16:00-18:00 Online',
        'IT 113 BSIT 2B tue 10:00-13:00 CompLab2',
        'IT 113 BSIT 2B wed 16:00-18:00 Online',
        'IT 114 BSIT 2B tue 13:00-16:00 CompLab2',
        'IT 114 BSIT 2B mon 07:00-09:00 Online',
        'IT 115 BSIT 2B wed 07:00-10:00 CompLab4',
        'IT 115 BSIT 2B fri 14:00-16:00 Online',
        'IT 116 BSIT 2B thu 13:00-16:00 CompLab2',
        'IT 116 BSIT 2B mon 09:00-11:00 Online',
        'PATH FIT 4 BSIT 2B fri 10:00-12:00 FIELD',
        'GEC 4 BSIT 2B mon 11:30-13:00 5 - 105',
        'GEC 4 BSIT 2B wed 11:30-13:00 5 - 105',
        'GEE 6 BSIT 2B tue 08:30-10:00 NEE 204',
        'GEE 6 BSIT 2B thu 08:30-10:00 NEE 204',
        'GEE 8 BSIT 2B mon 13:00-14:30 NEE 204',
        'GEE 8 BSIT 2B wed 13:00-14:30 Online',
    ];

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed([CollegeSeeder::class, ProgramSeeder::class]);
    }

    /**
     * @param  list<string>  $expected
     */
    #[DataProvider('fixtures')]
    public function test_each_layout_gives_one_meeting_per_day_with_24_hour_times(string $fixture, array $expected): void
    {
        $meetings = (new CorExtractor)->parseText(CorFixture::text($fixture))->meetings();

        $this->assertSame($expected, array_map(
            fn (array $meeting) => "{$meeting['subject']} {$meeting['section']} {$meeting['day']} {$meeting['starts_at']}-{$meeting['ends_at']} {$meeting['room_text']}",
            $meetings,
        ));
    }

    /**
     * @return array<string, array{string, list<string>}>
     */
    public static function fixtures(): array
    {
        return [
            'current layout (smalot): meetings on their own lines, or glued to the units' => [CorFixture::CURRENT_SMALOT, self::CURRENT_MEETINGS],
            'current layout (pdftotext): the subject row sits between its meeting lines' => [CorFixture::CURRENT_LAYOUT, self::CURRENT_MEETINGS],
            'older layout (smalot): repeated course rows, MW and TTH, two AM/PM' => [CorFixture::OLDER_SMALOT, self::OLDER_MEETINGS],
        ];
    }

    public function test_a_meeting_line_does_not_change_the_subjects_or_units(): void
    {
        $cor = (new CorExtractor)->parseText(CorFixture::text(CorFixture::CURRENT_SMALOT));

        $this->assertCount(9, $cor->subjects);
        $this->assertSame(27.0, array_sum(array_column($cor->subjects, 'units')));
    }

    /**
     * @param  list<Weekday>  $expected
     */
    #[DataProvider('dayTexts')]
    public function test_the_day_column_becomes_weekdays(string $text, array $expected): void
    {
        $this->assertSame($expected, Weekday::fromCorText($text));
    }

    /**
     * @return array<string, array{string, list<Weekday>}>
     */
    public static function dayTexts(): array
    {
        return [
            'one day' => ['Thu', [Weekday::Thu]],
            'upper case' => ['SAT', [Weekday::Sat]],
            'slash' => ['Mon/Wed', [Weekday::Mon, Weekday::Wed]],
            'letters MW' => ['MW', [Weekday::Mon, Weekday::Wed]],
            'letters TTH' => ['TTH', [Weekday::Tue, Weekday::Thu]],
            'letters MWF' => ['MWF', [Weekday::Mon, Weekday::Wed, Weekday::Fri]],
            'S is Saturday' => ['S', [Weekday::Sat]],
            'not a day' => ['Room', []],
        ];
    }
}
