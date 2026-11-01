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
    public const FAILED_MESSAGE = 'These credentials do not match our records.';

    /**
     * Find the user by email (if the login contains "@") or school ID, and check the password.
     *
     * @throws ValidationException (key "login") when the credentials are wrong.
     */
    public function attempt(string $login, string $password): User
    {
        $user = $this->findByLogin($login);

        if ($user === null || ! Hash::check($password, $user->password)) {
            throw ValidationException::withMessages(['login' => self::FAILED_MESSAGE]);
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
