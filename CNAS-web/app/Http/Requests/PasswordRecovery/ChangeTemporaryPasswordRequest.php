<?php

namespace App\Http\Requests\PasswordRecovery;

use Closure;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rules\Password;

/**
 * "Choose a new password" after signing in with a temporary password from the admin (users.must_change_password).
 * The new password twice; it can't be the temporary one.
 */
class ChangeTemporaryPasswordRequest extends FormRequest
{
    /**
     * Only while the account has a temporary password. Without one, the password changes in Settings, which asks for
     * the current password, so a stolen session can't take over the account here.
     */
    public function authorize(): bool
    {
        return $this->user()?->must_change_password === true;
    }

    /**
     * @return array<string, list<mixed>>
     */
    public function rules(): array
    {
        return [
            'password' => ['required', 'string', 'confirmed', Password::defaults(), function (string $attribute, mixed $value, Closure $fail) {
                if (is_string($value) && Hash::check($value, $this->user()->password)) {
                    $fail('Choose a password different from the temporary one.');
                }
            }],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'password.required' => 'Enter a new password.',
            'password.confirmed' => 'The two new passwords don’t match.',
        ];
    }
}
