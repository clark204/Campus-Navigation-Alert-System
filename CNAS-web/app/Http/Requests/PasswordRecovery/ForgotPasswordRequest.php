<?php

namespace App\Http\Requests\PasswordRecovery;

use Illuminate\Foundation\Http\FormRequest;

/**
 * "Forgot password?" (web and API): the email or student ID of the account, like signing in (LoginService::findByLogin).
 */
class ForgotPasswordRequest extends FormRequest
{
    /**
     * @return array<string, list<string>>
     */
    public function rules(): array
    {
        return ['login' => ['required', 'string', 'max:255']];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return ['login.required' => 'Enter your email or student ID.'];
    }
}
