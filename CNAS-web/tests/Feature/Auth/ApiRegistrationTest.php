<?php

namespace Tests\Feature\Auth;

use App\Enums\Semester;
use App\Enums\UserRole;
use App\Models\AcademicTerm;
use App\Models\College;
use App\Models\RegistrationDraft;
use App\Models\User;
use Database\Seeders\CollegeSeeder;
use Database\Seeders\ProgramSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\CorFixture;
use Tests\TestCase;

class ApiRegistrationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed([CollegeSeeder::class, ProgramSeeder::class]);
        AcademicTerm::factory()->term('2026-2027', Semester::First)->current()->create();
        $this->travelTo(now()->setDate(2026, 9, 1)->setTime(8, 0));
    }

    private function uploadCor(?UploadedFile $file = null): TestResponse
    {
        return $this->postJson(route('api.register.cor'), ['cor' => $file ?? CorFixture::upload()]);
    }

    private function draftToken(): string
    {
        return $this->uploadCor()->assertOk()->json('draft_token');
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function accountPayload(string $draftToken, array $overrides = []): array
    {
        return [
            'draft_token' => $draftToken,
            'email' => 'Juan@TCC.edu.ph',
            'password' => 'secret123',
            'password_confirmation' => 'secret123',
            ...$overrides,
        ];
    }

    public function test_a_valid_cor_returns_the_details_and_a_draft_token(): void
    {
        $response = $this->uploadCor();

        $response->assertOk()->assertExactJsonStructure([
            'draft_token', 'student_id', 'first_name', 'middle_name', 'last_name', 'college_id',
        ]);
        $response->assertJson([
            'student_id' => '20240001',
            'first_name' => 'Juan Miguel',
            'middle_name' => 'S',
            'last_name' => 'Dela Cruz',
            'college_id' => 'C01',
        ]);
        $response->assertCookieMissing(config('session.cookie'));

        $token = $response->json('draft_token');
        $this->assertSame(40, strlen($token));

        $draft = RegistrationDraft::sole();
        $this->assertSame(hash('sha256', $token), $draft->token_hash);
        $this->assertEquals(now()->addMinutes(30), $draft->expires_at);
        // Only identity fields are kept, nothing about the schedule or units.
        $this->assertSame(['student_id', 'full_name', 'first_name', 'middle_name', 'last_name', 'college_id'], array_keys($draft->data));
    }

    public function test_the_pdf_is_deleted_right_after_it_is_read(): void
    {
        Storage::fake('local');
        $file = CorFixture::upload();
        $path = $file->getPathname();

        $this->uploadCor($file)->assertOk();

        $this->assertFileDoesNotExist($path);
        $this->assertSame([], Storage::disk('local')->allFiles());
    }

    public function test_the_pdf_is_deleted_even_when_the_cor_is_rejected(): void
    {
        $file = CorFixture::upload(CorFixture::tampered('Total Units : 27', 'Total Units : 24'));
        $path = $file->getPathname();

        $this->uploadCor($file)->assertUnprocessable();

        $this->assertFileDoesNotExist($path);
    }

    /**
     * One message, never a list: the API returns exactly this under errors.
     */
    private function assertOnlyCorError(TestResponse $response, string $message): void
    {
        $response->assertUnprocessable()->assertJsonPath('errors', ['cor' => [$message]]);
    }

    public function test_a_file_that_is_not_a_pdf_is_rejected(): void
    {
        $this->assertOnlyCorError(
            $this->uploadCor(UploadedFile::fake()->image('cor.png')),
            'Please upload your COR as a PDF file (up to 5 MB).',
        );

        $this->assertSame(0, RegistrationDraft::count());
    }

    public function test_a_pdf_over_5_mb_is_rejected(): void
    {
        $this->assertOnlyCorError($this->uploadCor(CorFixture::oversizedUpload()), 'Please upload your COR as a PDF file (up to 5 MB).');
    }

    public function test_a_missing_file_is_rejected(): void
    {
        $this->assertOnlyCorError($this->postJson(route('api.register.cor')), 'Please upload your COR as a PDF file (up to 5 MB).');
    }

    public function test_an_unreadable_pdf_is_an_invalid_cor(): void
    {
        Log::spy();

        $this->assertOnlyCorError(
            $this->uploadCor(UploadedFile::fake()->createWithContent('cor.pdf', '%PDF-1.7 not really a PDF')),
            'Invalid COR.',
        );

        Log::shouldHaveReceived('info')->with('COR upload rejected', ['failed_checks' => ['unreadable_pdf']])->once();
    }

    #[DataProvider('tamperedCors')]
    public function test_a_tampered_cor_is_an_invalid_cor(string $search, string $replace, ?int $copy): void
    {
        $this->assertOnlyCorError(
            $this->uploadCor(CorFixture::upload(CorFixture::tampered($search, $replace, $copy))),
            'Invalid COR.',
        );

        $this->assertSame(0, RegistrationDraft::count());
    }

    /**
     * @return array<string, array{string, string, ?int}>
     */
    public static function tamperedCors(): array
    {
        return [
            'wrong total units' => ['Total Units : 27', 'Total Units : 24', null],
            '7-digit student ID' => ['20240001', '2024001', null],
            'missing header' => ['Certificate of Registration', 'Enrollment Form', 1],
            'mismatched copies' => ['Juan Miguel Dela Cruz S', 'Pedro Santos Reyes A', 2],
            'unknown program' => ['Program : BSIT', 'Program : BSXYZ', null],
            'unrecognised semester' => ['AY : 2026-2027 1st', 'AY : 2026-2027 3rd', null],
        ];
    }

    public function test_the_failed_checks_are_logged_without_personal_data(): void
    {
        Log::spy();

        $this->uploadCor(CorFixture::upload(CorFixture::tampered('Total Units : 27', 'Total Units : 24')))->assertUnprocessable();

        Log::shouldHaveReceived('info')->with('COR upload rejected', ['failed_checks' => ['total_units']])->once();
    }

    public function test_a_format_problem_wins_over_the_wrong_term(): void
    {
        // The older COR is for another term AND has wrong total units: "Invalid COR." comes first.
        $text = CorFixture::tampered('Total Units : 26', 'Total Units : 30', fixture: CorFixture::OLDER_SMALOT);

        $this->assertOnlyCorError($this->uploadCor(CorFixture::upload($text)), 'Invalid COR.');
    }

    public function test_a_cor_for_another_term_is_rejected(): void
    {
        Log::spy();

        $this->assertOnlyCorError(
            $this->uploadCor(CorFixture::upload(CorFixture::text(CorFixture::OLDER_SMALOT))),
            'Please upload your COR for AY 2026-2027, 1st Semester.',
        );

        Log::shouldHaveReceived('info')->with('COR upload rejected', ['failed_checks' => ['academic_term']])->once();
    }

    public function test_changing_the_current_term_rejects_the_current_cor(): void
    {
        AcademicTerm::factory()->term('2026-2027', Semester::Second)->create()->makeCurrent();

        $this->assertOnlyCorError($this->uploadCor(), 'Please upload your COR for AY 2026-2027, 2nd Semester.');
    }

    public function test_registration_is_closed_without_a_current_term(): void
    {
        AcademicTerm::query()->delete();

        $this->uploadCor()
            ->assertUnprocessable()
            ->assertJsonPath('errors.cor', ['Registration is closed right now. Please try again later.']);
    }

    public function test_a_cor_whose_student_id_is_already_registered_is_rejected(): void
    {
        User::factory()->create(['school_id' => CorFixture::STUDENT_ID]);

        $this->uploadCor()
            ->assertUnprocessable()
            ->assertJsonPath('errors.cor', [
                'An account already exists for this student ID. If this wasn\'t you, please contact the CNAS administrator using the contact details on the home page.',
            ]);
    }

    public function test_completing_a_draft_creates_an_active_student(): void
    {
        $token = $this->draftToken();

        $response = $this->postJson(route('api.register'), $this->accountPayload($token));

        $user = User::sole();
        $response->assertCreated()->assertExactJson([
            'id' => $user->id,
            'school_id' => '20240001',
            'first_name' => 'Juan Miguel',
            'middle_name' => 'S',
            'last_name' => 'Dela Cruz',
            'email' => 'juan@tcc.edu.ph',
            'role' => 'student',
            'college' => ['id' => 'C01', 'name' => College::find('C01')->name],
        ]);

        $this->assertSame(UserRole::Student, $user->role);
        $this->assertEquals(now(), $user->verified_at);
        $this->assertTrue(Hash::check('secret123', $user->password));
        $this->assertSame(0, RegistrationDraft::count());
    }

    public function test_student_id_college_and_role_cannot_be_set_by_the_client(): void
    {
        $token = $this->draftToken();

        $this->postJson(route('api.register'), $this->accountPayload($token, [
            'student_id' => '20249999', 'school_id' => '20249999', 'college_id' => 'C02', 'role' => 'admin',
        ]))->assertCreated();

        $user = User::sole();
        $this->assertSame(['20240001', 'C01', UserRole::Student], [$user->school_id, $user->college_id, $user->role]);
    }

    public function test_without_name_fields_the_parsers_split_is_used(): void
    {
        $token = $this->draftToken();

        $this->postJson(route('api.register'), $this->accountPayload($token))->assertCreated();

        $user = User::sole();
        $this->assertSame(['Juan Miguel', 'S', 'Dela Cruz'], [$user->first_name, $user->middle_name, $user->last_name]);
    }

    public function test_the_parsers_own_split_is_accepted_when_sent_back(): void
    {
        // Reported bug: COR order is first, last, middle initial ("Pedro Jose Ramos L").
        $token = $this->uploadCor(CorFixture::upload(CorFixture::tampered('Juan Miguel Dela Cruz S', 'Pedro Jose Ramos L')))
            ->assertOk()
            ->assertJson(['first_name' => 'Pedro Jose', 'middle_name' => 'L', 'last_name' => 'Ramos'])
            ->json('draft_token');

        $this->postJson(route('api.register'), $this->accountPayload($token, [
            'first_name' => 'Pedro Jose', 'middle_name' => 'L', 'last_name' => 'Ramos',
        ]))->assertCreated();
    }

    /**
     * @param  array<string, ?string>  $names
     * @param  array{0: string, 1: ?string, 2: string}  $expected
     */
    #[DataProvider('resplitNames')]
    public function test_the_name_can_be_re_split(array $names, array $expected): void
    {
        $token = $this->draftToken();

        $this->postJson(route('api.register'), $this->accountPayload($token, $names))->assertCreated();

        $this->assertSame($expected, [User::sole()->first_name, User::sole()->middle_name, User::sole()->last_name]);
    }

    /**
     * @return array<string, array{array<string, ?string>, array{0: string, 1: ?string, 2: string}}>
     */
    public static function resplitNames(): array
    {
        return [
            'words moved between first and last' => [
                ['first_name' => 'Juan', 'last_name' => 'Miguel Dela Cruz'], ['Juan', 'S', 'Miguel Dela Cruz'],
            ],
            'middle initial written out' => [['middle_name' => 'Santos'], ['Juan Miguel', 'Santos', 'Dela Cruz']],
            'initial kept in the last name' => [
                ['middle_name' => null, 'last_name' => 'Dela Cruz S'], ['Juan Miguel', null, 'Dela Cruz S'],
            ],
            'different case and dots' => [
                ['first_name' => 'JUAN MIGUEL', 'middle_name' => 's.', 'last_name' => 'dela cruz'], ['JUAN MIGUEL', 's.', 'dela cruz'],
            ],
        ];
    }

    /**
     * @param  array<string, string>  $names
     */
    #[DataProvider('changedNames')]
    public function test_a_changed_name_is_rejected(array $names): void
    {
        $token = $this->draftToken();

        $this->postJson(route('api.register'), $this->accountPayload($token, $names))
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['first_name' => 'Your name must match your COR (Juan Miguel Dela Cruz S).']);

        $this->assertSame(0, User::count());
    }

    /**
     * @return array<string, array{array<string, string>}>
     */
    public static function changedNames(): array
    {
        return [
            'different first name' => [['first_name' => 'Pedro']],
            'middle name with another initial' => [['middle_name' => 'Reyes']],
            'middle initial dropped' => [['middle_name' => '']],
            'extra word' => [['last_name' => 'Dela Cruz Santos']],
            'word dropped' => [['first_name' => 'Juan']],
        ];
    }

    public function test_a_draft_token_can_only_be_used_once(): void
    {
        $token = $this->draftToken();

        $this->postJson(route('api.register'), $this->accountPayload($token))->assertCreated();
        $this->postJson(route('api.register'), $this->accountPayload($token, ['email' => 'other@tcc.edu.ph']))
            ->assertUnprocessable()
            ->assertJsonValidationErrors('draft_token');

        $this->assertSame(1, User::count());
    }

    public function test_an_expired_draft_token_is_rejected(): void
    {
        $token = $this->draftToken();
        $this->travel(31)->minutes();

        $this->postJson(route('api.register'), $this->accountPayload($token))
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['draft_token' => 'Your registration expired or is invalid.']);

        $this->assertSame(0, User::count());
    }

    public function test_an_unknown_draft_token_is_rejected(): void
    {
        $this->postJson(route('api.register'), $this->accountPayload(str_repeat('a', 40)))
            ->assertUnprocessable()
            ->assertJsonValidationErrors('draft_token');
    }

    public function test_a_student_id_registered_after_the_upload_is_rejected(): void
    {
        $token = $this->draftToken();
        User::factory()->create(['school_id' => CorFixture::STUDENT_ID]);

        $this->postJson(route('api.register'), $this->accountPayload($token))
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['student_id' => 'An account already exists for this student ID.']);
    }

    public function test_cor_uploads_are_rate_limited(): void
    {
        foreach (range(1, 5) as $attempt) {
            $this->uploadCor(UploadedFile::fake()->image('cor.png'))->assertUnprocessable();
        }

        $this->uploadCor()
            ->assertTooManyRequests()
            ->assertJsonPath('message', 'Too many COR uploads. Please try again in 60 seconds.');
    }

    public function test_account_creation_is_rate_limited(): void
    {
        foreach (range(1, 5) as $attempt) {
            $this->postJson(route('api.register'), [])->assertUnprocessable();
        }

        $this->postJson(route('api.register'), [])->assertTooManyRequests();
    }
}
