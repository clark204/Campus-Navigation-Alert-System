<?php

namespace App\Http\Requests\Admin;

use App\Enums\UserRole;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;

/**
 * Admin > Accounts > Create account: a faculty, staff or student account made by the admin (faculty and staff can't
 * register themselves: registration needs a student COR). Admin accounts can't be made here.
 */
class StoreAccountRequest extends FormRequest
{
    /** Roles the admin can create. */
    public const ROLES = [UserRole::Faculty, UserRole::Staff, UserRole::Student];

    public function authorize(): bool
    {
        return $this->user()?->isAdmin() ?? false;
    }

    protected function prepareForValidation(): void
    {
        $this->merge(array_map(
            fn ($value) => is_string($value) ? (trim($value) === '' ? null : trim($value)) : $value,
            $this->only(['first_name', 'middle_name', 'last_name', 'school_id', 'program_code']),
        ));
        if (is_string($this->email)) {
            $this->merge(['email' => strtolower(trim($this->email))]);
        }
    }

    public function role(): ?UserRole
    {
        return UserRole::tryFrom((string) $this->input('role'));
    }

    /**
     * @return array<string, list<mixed>>
     */
    public function rules(): array
    {
        $isStudent = $this->role() === UserRole::Student;

        return [
            'role' => ['required', Rule::in(array_map(fn (UserRole $role) => $role->value, self::ROLES))],
            'first_name' => ['required', 'string', 'max:100'],
            'middle_name' => ['nullable', 'string', 'max:100'],
            'last_name' => ['required', 'string', 'max:100'],
            'email' => ['required', 'string', 'email:rfc,strict', 'max:255', Rule::unique('users', 'email')],
            // Students: the 8-digit ID on their COR. Faculty and staff: an employee ID, if they have one.
            'school_id' => $isStudent
                ? ['required', 'digits:8', Rule::unique('users', 'school_id')]
                : ['nullable', 'string', 'max:20', 'alpha_dash', Rule::unique('users', 'school_id')],
            'program_code' => [$isStudent ? 'required' : 'nullable', 'string', Rule::exists('programs', 'code')],
            'password' => ['required', 'string', Password::defaults()],
            'trusted_for_alerts' => ['nullable', 'boolean'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'role.required' => 'Choose what kind of account this is.',
            'role.in' => 'Choose faculty, staff or student.',
            'first_name.required' => 'Enter their first name.',
            'last_name.required' => 'Enter their last name.',
            'email.required' => 'Enter the email they will sign in with.',
            'email.email' => 'Enter a real email address, like name@example.com.',
            'email.unique' => 'Another account already uses this email.',
            'school_id.required' => 'Enter the student ID from their COR.',
            'school_id.digits' => 'A student ID has 8 digits, like 20260412.',
            'school_id.alpha_dash' => 'Use only letters, numbers and dashes.',
            'school_id.unique' => 'Another account already has this ID.',
            'program_code.required' => 'Choose the student’s program.',
            'program_code.exists' => 'Choose a program from the list.',
            'password.required' => 'Set a temporary password, or tap Generate.',
        ];
    }
}
