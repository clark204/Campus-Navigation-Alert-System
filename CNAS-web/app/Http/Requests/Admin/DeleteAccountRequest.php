<?php

namespace App\Http\Requests\Admin;

use App\Models\User;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Admin > Accounts > Delete: like deleting a GitHub repository, the admin types the account's email to confirm. The
 * page's button stays disabled until it matches (account-delete.js); this checks it again on the server.
 */
class DeleteAccountRequest extends FormRequest
{
    /** @var string */
    protected $errorBag = 'deleteAccount';

    public function authorize(): bool
    {
        return $this->user()?->isAdmin() ?? false;
    }

    /**
     * @return array<string, list<mixed>>
     */
    public function rules(): array
    {
        /** @var User $account */
        $account = $this->route('user');

        return [
            'confirm_email' => ['required', 'string', function (string $attribute, mixed $value, \Closure $fail) use ($account) {
                if (strtolower(trim((string) $value)) !== strtolower($account->email)) {
                    $fail('That doesn’t match. Type '.$account->email.' exactly to delete this account.');
                }
            }],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return ['confirm_email.required' => 'Type the account’s email to confirm.'];
    }

    protected function getRedirectUrl(): string
    {
        return route('admin.accounts.show', $this->route('user')).'#danger-zone';
    }
}
