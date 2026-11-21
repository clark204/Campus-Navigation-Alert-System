<?php

namespace App\Http\Requests\Admin;

use App\Enums\UserRole;
use App\Models\User;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Admin > Accounts > Edit details: the admin fixes someone's name, ID, program or email (e.g. a typo, or a student who
 * shifted programs). Same rules as creating an account (StoreAccountRequest), for the account's own role. The role is
 * changed elsewhere (Faculty or staff on the account page); passwords only by their owner. Admin accounts are edited
 * in Settings → My account, not here.
 */
class UpdateAccountRequest extends FormRequest
{
    public function authorize(): bool
    {
        return ($this->user()?->isAdmin() ?? false) && ! $this->account()->isAdmin();
    }

    public function account(): User
    {
        return $this->route('user');
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

    /**
     * @return array<string, list<mixed>>
     */
    public function rules(): array
    {
        $account = $this->account();
        $isStudent = $account->role === UserRole::Student;
        $hasProgram = in_array($account->role, [UserRole::Student, UserRole::Faculty], true);

        return [
            'first_name' => ['required', 'string', 'max:100'],
            'middle_name' => ['nullable', 'string', 'max:100'],
            'last_name' => ['required', 'string', 'max:100'],
            'email' => ['required', 'string', 'email:rfc,strict', 'max:255', Rule::unique('users', 'email')->ignore($account)],
            'school_id' => $isStudent
                ? ['required', 'digits:8', Rule::unique('users', 'school_id')->ignore($account)]
                : ['nullable', 'string', 'max:20', 'alpha_dash', Rule::unique('users', 'school_id')->ignore($account)],
            // Staff have no program (the field isn't shown); faculty may have one.
            'program_code' => $hasProgram
                ? [$isStudent ? 'required' : 'nullable', 'string', Rule::exists('programs', 'code')]
                : ['prohibited'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'first_name.required' => 'Enter their first name.',
            'last_name.required' => 'Enter their last name.',
            'email.required' => 'Enter the email they sign in with.',
            'email.email' => 'Enter a real email address, like name@example.com.',
            'email.unique' => 'Another account already uses this email.',
            'school_id.required' => 'Enter the student ID from their COR.',
            'school_id.digits' => 'A student ID has 8 digits, like 20260412.',
            'school_id.alpha_dash' => 'Use only letters, numbers and dashes.',
            'school_id.unique' => 'Another account already has this ID.',
            'program_code.required' => 'Choose the student’s program.',
            'program_code.exists' => 'Choose a program from the list.',
            'program_code.prohibited' => 'Staff don’t have a program.',
        ];
    }
}
