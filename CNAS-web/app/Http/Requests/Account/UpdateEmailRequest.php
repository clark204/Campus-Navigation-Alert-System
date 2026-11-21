<?php

namespace App\Http\Requests\Account;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Settings → My account: the signed-in user's own sign-in email (admin, faculty, staff or student).
 */
class UpdateEmailRequest extends FormRequest
{
    /** @var string */
    protected $errorBag = 'accountEmail';

    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    /**
     * Sign-in looks emails up in lower case (LoginService), so store them that way.
     */
    protected function prepareForValidation(): void
    {
        if (is_string($this->email)) {
            $this->merge(['email' => strtolower(trim($this->email))]);
        }
    }

    /**
     * @return array<string, list<mixed>>
     */
    public function rules(): array
    {
        return [
            'email' => ['required', 'string', 'email:rfc,strict', 'max:255', Rule::unique('users', 'email')->ignore($this->user())],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'email.required' => 'Enter the email you sign in with.',
            'email.email' => 'Enter a real email address, like name@example.com.',
            'email.unique' => 'Another account already uses this email.',
        ];
    }

    protected function getRedirectUrl(): string
    {
        return $this->user()->settingsUrl('my-account');
    }
}
