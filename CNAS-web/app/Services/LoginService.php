<?php

namespace App\Services;

use App\Models\User;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;

/**
 * Checks sign-in credentials, shared by the web login (session) and the API login (token).
 * The caller decides how to sign the user in.
 */
class LoginService
{
    /**
     * Deliberately generic: never reveals whether the email or school ID exists.
     */
    public const FAILED_MESSAGE = 'Invalid credentials.';

    /**
     * Shown under the password field (the web login marks both fields invalid).
     */
    public const FAILED_KEY = 'password';

    /**
     * Find the user by email (if the login contains "@") or school ID, and check the password.
     *
     * @throws ValidationException (key "password") when the credentials are wrong.
     */
    public function attempt(string $login, string $password): User
    {
        $user = $this->findByLogin($login);

        if ($user === null || ! Hash::check($password, $user->password)) {
            throw ValidationException::withMessages([self::FAILED_KEY => self::FAILED_MESSAGE]);
        }

        if (Hash::needsRehash($user->password)) {
            $user->forceFill(['password' => $password])->save();
        }

        return $user;
    }

    public function findByLogin(string $login): ?User
    {
        $login = trim($login);

        if ($login === '') {
            return null;
        }

        return str_contains($login, '@')
            ? User::where('email', strtolower($login))->first()
            : User::where('school_id', $login)->first();
    }
}
