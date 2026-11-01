<?php

namespace App\Http\Requests;

use App\Services\LoginService;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\ValidationException;

class LoginRequest extends FormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'login' => ['required', 'string', 'max:255'],
            'password' => ['required', 'string'],
            'remember' => ['nullable', 'boolean'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'login.required' => 'Enter your ID number or email.',
            'password.required' => 'Enter your password.',
        ];
    }

    /**
     * Sign in to the session with either an email address or a school ID.
     *
     * @throws ValidationException
     */
    public function authenticate(LoginService $logins): void
    {
        $user = $logins->attempt($this->string('login')->toString(), $this->string('password')->toString());

        Auth::login($user, $this->boolean('remember'));
    }
}
