<?php

namespace Tests\Feature\Auth;

use App\Enums\UserRole;
use App\Models\User;
use Database\Seeders\CollegeSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Laravel\Sanctum\PersonalAccessToken;
use Tests\TestCase;

class ApiLoginTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(CollegeSeeder::class);
        $this->user = User::factory()->create([
            'school_id' => '20240001',
            'first_name' => 'Juan Miguel',
            'middle_name' => 'S',
            'last_name' => 'Dela Cruz',
            'email' => 'juan@tcc.edu.ph',
            'college_id' => 'C01',
            'role' => UserRole::Student,
        ]);
    }

    /**
     * @param  array<string, string>  $overrides
     */
    private function login(array $overrides = []): TestResponse
    {
        return $this->postJson(route('api.login'), [
            'login' => '20240001',
            'password' => 'password',
            'device_name' => "Clark's Android",
            ...$overrides,
        ]);
    }

    /**
     * Each request authenticates from scratch, as separate requests from the app would.
     */
    private function asDevice(string $token): static
    {
        $this->app['auth']->forgetGuards();

        return $this->withHeader('Authorization', "Bearer {$token}");
    }

    public function test_a_user_signs_in_with_their_email_and_gets_a_token_named_after_the_device(): void
    {
        $response = $this->login(['login' => ' Juan@TCC.edu.ph '])->assertOk();

        $response->assertExactJson([
            'token' => $response->json('token'),
            'user' => [
                'id' => $this->user->id,
                'school_id' => '20240001',
                'first_name' => 'Juan Miguel',
                'middle_name' => 'S',
                'last_name' => 'Dela Cruz',
                'email' => 'juan@tcc.edu.ph',
                'role' => 'student',
                'college' => ['id' => 'C01', 'name' => $this->user->college->name],
            ],
        ]);

        $token = PersonalAccessToken::findToken($response->json('token'));
        $this->assertTrue($token->tokenable->is($this->user));
        $this->assertSame("Clark's Android", $token->name);
    }

    public function test_a_student_signs_in_with_their_school_id(): void
    {
        $this->login()
            ->assertOk()
            ->assertJsonPath('user.school_id', '20240001')
            ->assertJsonStructure(['token']);

        $this->assertSame(1, $this->user->tokens()->count());
    }

    public function test_a_wrong_password_is_rejected(): void
    {
        $this->login(['password' => 'wrong'])
            ->assertUnprocessable()
            ->assertJsonPath('message', 'These credentials do not match our records.')
            ->assertJsonPath('errors', ['login' => ['These credentials do not match our records.']]);

        $this->assertSame(0, PersonalAccessToken::count());
    }

    public function test_an_unknown_login_is_rejected_with_the_same_message(): void
    {
        $this->login(['login' => 'nobody@tcc.edu.ph'])
            ->assertUnprocessable()
            ->assertJsonPath('errors', ['login' => ['These credentials do not match our records.']]);
    }

    public function test_the_device_name_is_required(): void
    {
        $this->login(['device_name' => ''])->assertUnprocessable()->assertJsonValidationErrors('device_name');
    }

    public function test_sign_in_is_rate_limited(): void
    {
        $this->freezeSecond();

        foreach (range(1, 5) as $attempt) {
            $this->login(['password' => 'wrong']);
        }

        $this->login()
            ->assertTooManyRequests()
            ->assertJsonPath('message', 'Too many sign-in attempts. Please try again in 60 seconds.');

        $this->assertSame(0, PersonalAccessToken::count());
    }

    public function test_me_returns_the_user_for_a_valid_token(): void
    {
        $token = $this->login()->json('token');

        $this->asDevice($token)->getJson(route('api.me'))
            ->assertOk()
            ->assertJsonPath('id', $this->user->id)
            ->assertJsonPath('college.id', 'C01')
            ->assertJsonMissingPath('password')
            ->assertJsonMissingPath('tokens');
    }

    public function test_me_needs_a_token(): void
    {
        $this->getJson(route('api.me'))->assertUnauthorized();
        $this->get(route('api.me'))->assertUnauthorized();
        $this->asDevice('1|not-a-real-token')->getJson(route('api.me'))->assertUnauthorized();
    }

    public function test_logout_revokes_only_the_current_token(): void
    {
        $phone = $this->login(['device_name' => 'Phone'])->json('token');
        $tablet = $this->login(['device_name' => 'Tablet'])->json('token');

        $this->asDevice($phone)->postJson(route('api.logout'))->assertNoContent();

        $this->assertSame(['Tablet'], $this->user->tokens()->pluck('name')->all());
        $this->asDevice($phone)->getJson(route('api.me'))->assertUnauthorized();
        $this->asDevice($tablet)->getJson(route('api.me'))->assertOk();
    }

    public function test_logout_needs_a_token(): void
    {
        $this->postJson(route('api.logout'))->assertUnauthorized();
    }

    public function test_a_token_expires_after_30_days(): void
    {
        $token = $this->login()->json('token');

        $this->travel(30)->days();
        $this->travel(-1)->minutes();
        $this->asDevice($token)->getJson(route('api.me'))->assertOk();

        $this->travel(2)->minutes();
        $this->asDevice($token)->getJson(route('api.me'))->assertUnauthorized();
    }
}
