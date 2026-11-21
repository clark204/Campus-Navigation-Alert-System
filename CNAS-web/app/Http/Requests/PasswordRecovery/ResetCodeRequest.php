<?php

namespace App\Http\Requests\PasswordRecovery;

use App\Services\EmailCodes;
use App\Services\PasswordRecovery;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Password reset, step 1 (web and API): the emailed code, checked on its own. The API sends the email or student ID
 * as `login`; the web reset page keeps it in the session (PasswordRecovery::SESSION_LOGIN). Spaces and dashes in the
 * code are ignored.
 */
class ResetCodeRequest extends FormRequest
{
    protected function prepareForValidation(): void
    {
        if (is_string($this->code)) {
            $this->merge(['code' => preg_replace('/[\s-]+/', '', $this->code)]);
        }
        if (! $this->filled('login') && $this->hasSession()) {
            $this->merge(['login' => $this->session()->get(PasswordRecovery::SESSION_LOGIN)]);
        }
    }

    /**
     * @return array<string, list<string>>
     */
    public function rules(): array
    {
        return [
            'login' => ['required', 'string', 'max:255'],
            'code' => ['required', 'string', 'digits:'.EmailCodes::LENGTH],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'login.required' => 'Enter your email or student ID.',
            'code.required' => 'Enter the '.EmailCodes::LENGTH.'-digit code from the email.',
            'code.digits' => 'The code is '.EmailCodes::LENGTH.' digits, like 123456.',
        ];
    }
}
