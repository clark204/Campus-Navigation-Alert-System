<?php

namespace App\Http\Requests\Account;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rules\Password;

/**
 * Settings → My account: the signed-in user's own password. Needs the current password and the new one twice.
 */
class UpdatePasswordRequest extends FormRequest
{
    /** @var string */
    protected $errorBag = 'accountPassword';

    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    /**
     * @return array<string, list<mixed>>
     */
    public function rules(): array
    {
        return [
            'current_password' => ['required', 'string', 'current_password:web'],
            'password' => ['required', 'string', 'confirmed', 'different:current_password', Password::defaults()],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'current_password.required' => 'Enter your current password.',
            'current_password.current_password' => 'That is not your current password.',
            'password.required' => 'Enter a new password.',
            'password.confirmed' => 'The two new passwords don’t match.',
            'password.different' => 'Choose a password different from your current one.',
        ];
    }

    protected function getRedirectUrl(): string
    {
        return $this->user()->settingsUrl('my-account');
    }
}
