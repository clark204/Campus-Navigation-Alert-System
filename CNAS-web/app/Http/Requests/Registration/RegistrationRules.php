<?php

namespace App\Http\Requests\Registration;

use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;

/**
 * Validation shared by the web registration steps and the API, so both accept the same input.
 * Student ID and program are never accepted from the request; they come from the COR.
 */
trait RegistrationRules
{
    /**
     * Normalise the email before validation so the uniqueness check compares like with like.
     */
    protected function prepareForValidation(): void
    {
        if (is_string($this->email)) {
            $this->merge(['email' => strtolower(trim($this->email))]);
        }
    }

    /**
     * Optional: a field left out keeps the parser's split. RegistrationService checks the
     * resulting name against the COR (re-splitting only).
     *
     * @return array<string, list<string>>
     */
    protected function nameRules(): array
    {
        return [
            'first_name' => ['sometimes', 'required', 'string', 'max:100'],
            'middle_name' => ['sometimes', 'nullable', 'string', 'max:100'],
            'last_name' => ['sometimes', 'required', 'string', 'max:100'],
        ];
    }

    /**
     * @return array<string, list<mixed>>
     */
    protected function accountRules(): array
    {
        return [
            'email' => ['required', 'string', 'email', 'max:255', Rule::unique('users', 'email')],
            'password' => ['required', 'string', 'confirmed', Password::min(8)->letters()->numbers()],
        ];
    }

    /**
     * "Also save my class schedule". Left out means no.
     *
     * @return array<string, list<string>>
     */
    protected function scheduleRules(): array
    {
        return ['save_schedule' => ['sometimes', 'boolean']];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'email.unique' => 'An account with this email already exists.',
            'draft_token.required' => 'Your registration expired or is invalid. Please upload your COR again.',
            'draft_token.size' => 'Your registration expired or is invalid. Please upload your COR again.',
        ];
    }
}
