<?php

namespace App\Http\Requests\PasswordRecovery;

use App\Services\PasswordRecovery;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rules\Password;

/**
 * Password reset, step 2 (web and API): the new password twice, after the code was right. The API sends the reset
 * grant from step 1 as `reset_token`; the web keeps it in the session (PasswordRecovery::SESSION_GRANT).
 */
class ResetPasswordRequest extends FormRequest
{
    protected function prepareForValidation(): void
    {
        if (! $this->filled('reset_token') && $this->hasSession()) {
            $this->merge(['reset_token' => $this->session()->get(PasswordRecovery::SESSION_GRANT)]);
        }
    }

    /**
     * @return array<string, list<mixed>>
     */
    public function rules(): array
    {
        return [
            'reset_token' => ['required', 'string'],
            'password' => ['required', 'string', 'confirmed', Password::defaults()],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'reset_token.required' => PasswordRecovery::GRANT_EXPIRED_MESSAGE,
            'password.required' => 'Enter a new password.',
            'password.confirmed' => 'The two new passwords don’t match.',
        ];
    }
}
