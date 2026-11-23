<?php

namespace App\Http\Requests\Admin;

use App\Enums\UserRole;
use App\Services\AccountAdministration;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Admin > Accounts > Faculty or staff (web and mobile app): the new role, one of AccountAdministration::SWITCHABLE_ROLES.
 */
class SwitchRoleRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->isAdmin() ?? false;
    }

    /**
     * @return array<string, list<mixed>>
     */
    public function rules(): array
    {
        return ['role' => ['required', Rule::in(array_map(fn (UserRole $role) => $role->value, AccountAdministration::SWITCHABLE_ROLES))]];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return ['role.in' => 'Choose faculty or staff.'];
    }

    public function role(): UserRole
    {
        return UserRole::from((string) $this->validated('role'));
    }
}
