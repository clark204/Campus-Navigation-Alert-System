<?php

namespace Tests\Feature\Auth;

use App\Enums\EmailCodePurpose;
use App\Enums\Semester;
use App\Enums\UserRole;
use App\Mail\EmailCodeMail;
use App\Models\AcademicTerm;
use App\Models\RegistrationDraft;
use App\Models\User;
use Database\Seeders\CollegeSeeder;
use Database\Seeders\ProgramSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Tests\Support\CorFixture;
use Tests\TestCase;

/**
 * Web flow: 1. upload COR → 2. create account (verified COR card + email and password).
 */
class RegistrationTest extends TestCase
{
    use RefreshDatabase;

    private const DUPLICATE_MESSAGE = 'An account already exists for this student ID. If this wasn\'t you, please contact the CNAS administrator using the contact details on the home page.';

    private const NAME_MESSAGE = 'Your name must match your COR (Juan Miguel Dela Cruz S). You can only change how it is split into first, middle and last name, and write your middle initial out in full.';

    protected function setUp(): void
    {
        parent::setUp();

        // CORs are never meant to be kept; if a change ever stores one, it lands on this fake disk (and fails
        // test_the_uploaded_cor_is_never_kept_in_storage) instead of piling up in storage/app/private.
        Storage::fake('local');
        $this->seed([CollegeSeeder::class, ProgramSeeder::class]);
        AcademicTerm::factory()->term('2026-2027', Semester::First)->current()->create();
        $this->travelTo(now()->setDate(2026, 9, 1)->setTime(8, 0));
    }

    private function uploadCor(?UploadedFile $file = null, bool $consent = true): TestResponse
    {
        return $this->post(route('register.cor'), array_filter([
            'cor' => $file ?? CorFixture::upload(),
            'consent' => $consent ? '1' : null,
        ]));
    }

    /**
     * Without name fields, as when the student never opens "Edit name".
     *
     * @param  array<string, ?string>  $overrides
     */
    private function createAccount(array $overrides = []): TestResponse
    {
        return $this->from(route('register.confirm'))->post(route('register.store'), [
            'idempotency_key' => (string) Str::uuid(),
            'email' => 'Juan@TCC.edu.ph',
            'password' => 'secret123',
            'password_confirmation' => 'secret123',
            ...$overrides,
        ]);
    }

    public function test_step_one_names_the_expected_term_and_shows_the_privacy_notice(): void
    {
        $this->get(route('register'))
            ->assertOk()
            ->assertSeeInOrder(['Upload COR', 'Create account'])
            ->assertSeeInOrder(['Upload your COR for', 'AY 2026-2027, 1st Semester'])
            ->assertSee('name="cor"', false)
            ->assertSee('Data Privacy Act of 2012 (RA 10173)');
    }

    public function test_step_one_says_registration_is_closed_without_a_current_term(): void
    {
        AcademicTerm::query()->delete();

        $this->get(route('register'))
            ->assertOk()
            ->assertSee('Registration is closed right now. Please try again later.')
            ->assertDontSee('name="cor"', false);

        $this->uploadCor()->assertSessionHasErrors(['cor' => 'Registration is closed right now. Please try again later.']);
    }

    public function test_consent_is_required(): void
    {
        $this->uploadCor(consent: false)
            ->assertSessionHasErrors(['consent' => 'Please agree to the data privacy notice to continue.']);

        $this->assertSame(0, RegistrationDraft::count());
    }

    public function test_a_file_that_is_not_a_pdf_is_rejected(): void
    {
        $this->uploadCor(UploadedFile::fake()->image('cor.jpg'))
            ->assertSessionHasErrors(['cor' => 'Please upload your COR as a PDF file (up to 5 MB).']);
    }

    public function test_a_pdf_over_5_mb_is_rejected(): void
    {
        $this->uploadCor(CorFixture::oversizedUpload())
            ->assertSessionHasErrors(['cor' => 'Please upload your COR as a PDF file (up to 5 MB).']);
    }

    public function test_a_tampered_cor_shows_one_invalid_cor_message(): void
    {
        $this->uploadCor(CorFixture::upload(CorFixture::tampered('20240001', '2024001')))
            ->assertSessionHasErrors(['cor' => 'Invalid COR.']);

        $this->assertSame(['Invalid COR.'], session('errors')->get('cor'));
    }

    public function test_a_cor_for_another_term_is_rejected(): void
    {
        $this->uploadCor(CorFixture::upload(CorFixture::text(CorFixture::OLDER_SMALOT)))
            ->assertSessionHasErrors(['cor' => 'Please upload your COR for AY 2026-2027, 1st Semester.']);
    }

    public function test_a_duplicate_student_id_is_rejected_with_a_link_to_the_contact_details(): void
    {
        User::factory()->create(['school_id' => CorFixture::STUDENT_ID]);

        // Follow the redirect back to the form (asserting the session first would hide the errors from the next request).
        $this->from(route('register'))->followingRedirects()
            ->post(route('register.cor'), ['cor' => CorFixture::upload(), 'consent' => '1'])
            ->assertOk()
            ->assertSee(e(self::DUPLICATE_MESSAGE), false)
            ->assertSee('<a href="#contact"', false)
            ->assertSee('<footer id="contact"', false);

        $this->assertSame(0, RegistrationDraft::count());
    }

    public function test_after_upload_the_create_account_screen_shows_the_verified_cor(): void
    {
        $this->uploadCor()->assertRedirect(route('register.confirm'));

        $response = $this->get(route('register.confirm'))
            ->assertOk()
            ->assertSeeInOrder(['COR verified', 'Juan Miguel S. Dela Cruz', '20240001', 'College of Information Technology', 'Bachelor of Science in Information Technology'])
            ->assertDontSee('name="student_id"', false)
            ->assertDontSee('name="program_code"', false)
            ->assertSee('value="Juan Miguel"', false)
            ->assertSee('value="Dela Cruz"', false)
            ->assertSee('name="email"', false)
            ->assertSee('name="password_confirmation"', false)
            ->assertSee('Upload a different COR');

        // "Edit name" is closed by default and pre-filled with the parser's split.
        $this->assertMatchesRegularExpression('/<details data-name-editor\s+class=/', $response->getContent());
    }

    public function test_the_uploaded_cor_is_never_kept_in_storage(): void
    {
        $file = CorFixture::upload();
        $path = $file->getPathname();

        $this->uploadCor($file)->assertRedirect(route('register.confirm'));
        $this->createAccount()->assertRedirect(route('email.verify'));

        $this->assertFileDoesNotExist($path);
        $this->assertSame([], Storage::disk('local')->allFiles());
    }

    public function test_the_create_account_screen_needs_a_draft(): void
    {
        $this->get(route('register.confirm'))->assertRedirect(route('register'));
    }

    public function test_without_editing_the_name_the_parsers_split_is_used(): void
    {
        Mail::fake();
        $this->uploadCor();

        $this->createAccount()
            ->assertRedirect(route('email.verify'))
            ->assertSessionHas('status', 'Account created. We sent a 6-digit code to juan@tcc.edu.ph.')
            ->assertSessionMissing('registration.draft_token');

        $user = User::sole();
        $this->assertSame(['20240001', 'BSIT'], [$user->school_id, $user->program_code]);
        $this->assertSame(['Juan Miguel', 'S', 'Dela Cruz'], [$user->first_name, $user->middle_name, $user->last_name]);
        $this->assertSame('juan@tcc.edu.ph', $user->email);
        $this->assertSame(UserRole::Student, $user->role);
        $this->assertNotNull($user->verified_at);
        $this->assertSame(0, RegistrationDraft::count());

        // Signed in right away, on "Verify your email" with a code on its way (tests/Feature/Auth/EmailVerificationTest.php).
        $this->assertAuthenticatedAs($user);
        $this->assertNull($user->email_verified_at);
        Mail::assertQueued(EmailCodeMail::class, fn (EmailCodeMail $mail) => $mail->hasTo('juan@tcc.edu.ph')
            && $mail->purpose === EmailCodePurpose::VerifyEmail);
        $this->get(route('student.dashboard'))->assertRedirect(route('email.verify'));
    }

    public function test_an_edited_re_split_name_is_accepted(): void
    {
        $this->uploadCor();

        $this->createAccount(['first_name' => 'Juan', 'middle_name' => 'Santos', 'last_name' => 'Miguel Dela Cruz'])
            ->assertRedirect(route('email.verify'));

        $user = User::sole();
        $this->assertSame(['Juan', 'Santos', 'Miguel Dela Cruz'], [$user->first_name, $user->middle_name, $user->last_name]);
    }

    public function test_a_changed_name_is_rejected_and_edit_name_reopens(): void
    {
        $this->uploadCor();

        $this->createAccount(['first_name' => 'Pedro', 'middle_name' => 'S', 'last_name' => 'Dela Cruz'])
            ->assertRedirect(route('register.confirm'))
            ->assertSessionHasErrors(['first_name' => self::NAME_MESSAGE]);

        $this->assertSame(0, User::count());
    }

    public function test_the_name_error_is_shown_inside_an_open_edit_name(): void
    {
        $this->uploadCor();

        $response = $this->from(route('register.confirm'))->followingRedirects()
            ->post(route('register.store'), [
                'idempotency_key' => (string) Str::uuid(),
                'first_name' => 'Pedro', 'middle_name' => 'S', 'last_name' => 'Dela Cruz',
                'email' => 'juan@tcc.edu.ph', 'password' => 'secret123', 'password_confirmation' => 'secret123',
            ])
            ->assertOk()
            ->assertSee('value="Pedro"', false)
            ->assertSee(e(self::NAME_MESSAGE), false);

        $this->assertMatchesRegularExpression('/<details data-name-editor\s+open\s+class=/', $response->getContent());
    }

    public function test_student_id_and_program_in_the_request_are_ignored(): void
    {
        $this->uploadCor();
        $this->createAccount(['student_id' => '20249999', 'program_code' => 'BSM']);

        $user = User::sole();
        $this->assertSame(['20240001', 'BSIT'], [$user->school_id, $user->program_code]);
    }

    public function test_a_student_id_registered_before_the_last_step_restarts_the_flow(): void
    {
        $this->uploadCor();
        User::factory()->create(['school_id' => CorFixture::STUDENT_ID]);

        $this->createAccount()
            ->assertRedirect(route('register'))
            ->assertSessionHasErrors(['cor' => self::DUPLICATE_MESSAGE]);

        $this->assertSame(1, User::count());
    }

    public function test_an_expired_draft_restarts_the_flow(): void
    {
        $this->uploadCor();
        $this->travel(31)->minutes();

        $this->createAccount()
            ->assertRedirect(route('register'))
            ->assertSessionHasErrors(['cor' => 'Your registration expired. Please upload your COR again.']);

        $this->assertSame(0, User::count());
    }

    public function test_uploading_a_new_cor_replaces_the_previous_draft(): void
    {
        $this->uploadCor();
        $this->uploadCor();

        $this->assertSame(1, RegistrationDraft::count());
    }

    public function test_repeating_the_final_request_does_not_create_a_second_account(): void
    {
        $this->uploadCor();
        $key = (string) Str::uuid();

        $this->createAccount(['idempotency_key' => $key])->assertRedirect(route('email.verify'));
        $this->createAccount(['idempotency_key' => $key])
            ->assertRedirect(route('email.verify'))
            ->assertSessionHasNoErrors();

        $this->assertSame(1, User::count());
    }

    public function test_cor_uploads_are_rate_limited(): void
    {
        foreach (range(1, 5) as $attempt) {
            $this->uploadCor(UploadedFile::fake()->image('cor.jpg'));
        }

        $this->uploadCor()->assertSessionHasErrors(['form' => 'Too many COR uploads. Please try again in 60 seconds.']);
        $this->assertSame(0, RegistrationDraft::count());
    }

    public function test_account_creation_is_rate_limited(): void
    {
        foreach (range(1, 5) as $attempt) {
            $this->createAccount(['email' => 'not-an-email']);
        }

        $this->createAccount()->assertSessionHasErrors(['form' => 'Too many sign-up attempts. Please try again in 60 seconds.']);
    }
}
