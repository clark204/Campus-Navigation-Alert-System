<?php

namespace App\Http\Requests\Admin;

use App\Http\Controllers\Admin\SettingsController;
use App\Rules\PhoneNumber;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Settings → Contact details: the administrator contact shown in the site footer and the portal Help & contact page.
 * Errors go to their own bag ("contact") so they show only in this section of the settings page.
 */
class UpdateContactDetailsRequest extends FormRequest
{
    /** @var string */
    protected $errorBag = 'contact';

    public function authorize(): bool
    {
        return $this->user()?->isAdmin() ?? false;
    }

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
            'email' => ['required', 'string', 'email:rfc,strict', 'max:255'],
            'phone' => ['required', 'string', 'max:30', new PhoneNumber],
            'facebook' => ['required', 'string', 'url:https', 'max:255'],
            'address' => ['required', 'string', 'max:200'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'email.required' => 'Enter the email students can write to.',
            'email.email' => 'Enter a real email address, like name@example.com.',
            'phone.required' => 'Enter the phone number students can call.',
            'phone.max' => 'Keep the phone number to 30 characters or fewer.',
            'facebook.required' => 'Enter the Facebook page address.',
            'facebook.url' => 'Enter the full page address starting with https://.',
            'address.required' => 'Enter the address.',
            'address.max' => 'Keep the address to 200 characters or fewer.',
        ];
    }

    protected function getRedirectUrl(): string
    {
        return SettingsController::tabUrl('contact-details');
    }
}
