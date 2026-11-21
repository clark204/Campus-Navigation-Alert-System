<?php

namespace App\Services;

use App\Enums\EmailCodePurpose;
use App\Models\User;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Forgotten passwords (docs/registration.md, "Password recovery"), shared by the web and the API.
 *
 * requestReset() emails a reset_password code to the account's email, verified or not (a student whose email isn't
 * verified yet must still get back in). The answer is the same whether or not the account exists. verifyResetCode()
 * checks the code first and returns a one-time reset grant; resetWithGrant() then sets the new password. The password
 * form only appears after the code was right. A success sets a new remember token and ends all the user's web
 * sessions and app tokens (UserSessions).
 *
 * Someone who can't open their email goes to the administrator with their school ID (no self-service: a COR can be
 * copied or faked, so it can't prove who someone is). The admin fixes the email and sets a temporary password.
 */
class PasswordRecovery
{
    /** Web only: the email or student ID typed on "Forgot password?", for the reset page. */
    public const SESSION_LOGIN = 'password_reset.login';

    /** Web only: the reset grant after the code was right (step 2 of the reset page). */
    public const SESSION_GRANT = 'password_reset.grant';

    /** How long a checked code allows choosing the new password. */
    public const GRANT_TTL_MINUTES = 15;

    /** Same for every login, so nobody can check which accounts exist. */
    public const SENT_MESSAGE = 'If an account matches, we sent a 6-digit code to its email. It works for 10 minutes.';

    public const GRANT_EXPIRED_MESSAGE = 'Too much time passed since you entered the code. Send a new one.';

    public const RESET_CODE_MESSAGE = 'That code is wrong or has expired. Check the newest email from TCC NAV, or send a new code.';

    public function __construct(
        private LoginService $logins,
        private EmailCodes $codes,
        private UserSessions $sessions,
    ) {}

    /**
     * Email a reset code to the account with this email or student ID, if there is one. Says nothing either way: a
     * resend during the 60-second wait is skipped quietly, since an error would show the account exists.
     */
    public function requestReset(string $login): void
    {
        $user = $this->logins->findByLogin($login);

        if ($user === null) {
            return;
        }

        try {
            $this->codes->send($user, EmailCodePurpose::ResetPassword);
        } catch (ValidationException) {
            // Still waiting to resend: the code already sent keeps working.
        }
    }

    /**
     * Step 1 of the reset: check the code on its own. A right code is used up and becomes a reset grant: a random
     * token (only its hash is the cache key) that allows one new password within GRANT_TTL_MINUTES.
     *
     * Every failure gives the same RESET_CODE_MESSAGE (no "N tries left"): a different answer for an unknown
     * account would show which accounts exist. EmailCodes still counts wrong tries and locks the code after 5.
     *
     * @throws ValidationException (key "code") when there is no account, no code, or the code is wrong, expired or locked.
     */
    public function verifyResetCode(string $login, string $code): string
    {
        $user = $this->logins->findByLogin($login);

        try {
            if ($user === null) {
                throw ValidationException::withMessages(['code' => self::RESET_CODE_MESSAGE]);
            }
            $sentTo = $this->codes->verify($user, EmailCodePurpose::ResetPassword, $code);
        } catch (ValidationException) {
            throw ValidationException::withMessages(['code' => self::RESET_CODE_MESSAGE]);
        }

        $token = Str::random(40);
        Cache::put($this->grantKey($token), ['user_id' => $user->id, 'sent_to' => $sentTo], now()->addMinutes(self::GRANT_TTL_MINUTES));

        return $token;
    }

    public function hasGrant(string $token): bool
    {
        return Cache::has($this->grantKey($token));
    }

    /**
     * Step 2: the new password, with the grant from step 1 (used once). A new remember token stops "Remember me"
     * cookies; every session and app token ends.
     *
     * @throws ValidationException (key "reset_token") when the grant is missing, used or expired.
     */
    public function resetWithGrant(string $token, string $password): User
    {
        $grant = Cache::pull($this->grantKey($token));
        $user = is_array($grant) ? User::find($grant['user_id']) : null;

        if ($user === null) {
            throw ValidationException::withMessages(['reset_token' => self::GRANT_EXPIRED_MESSAGE]);
        }

        $changes = ['password' => $password, 'must_change_password' => false];
        if ($grant['sent_to'] === $user->email) {
            // Entering the code proved they own the email.
            $changes['email_verified_at'] = now();
        }

        $user->forceFill($changes);
        $user->setRememberToken(Str::random(60));
        $user->save();

        $this->sessions->endAll($user);

        return $user;
    }

    private function grantKey(string $token): string
    {
        return 'password-reset-grant:'.hash('sha256', $token);
    }
}
