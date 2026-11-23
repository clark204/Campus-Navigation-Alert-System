<?php

namespace App\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rules\Password;

/**
 * Admin > Accounts > Set temporary password (web and mobile app). Errors use the bag temporaryPassword on the web.
 */
class SetTemporaryPasswordRequest extends FormRequest
{
    /** @var string */
    protected $errorBag = 'temporaryPassword';

    public function authorize(): bool
    {
        return $this->user()?->isAdmin() ?? false;
    }

    /**
     * @return array<string, list<mixed>>
     */
    public function rules(): array
    {
        return ['password' => ['required', 'string', Password::defaults()]];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return ['password.required' => 'Type a temporary password, or tap Generate.'];
    }
}
