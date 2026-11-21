<?php

namespace App\Http\Requests\Admin;

use App\Http\Controllers\Admin\SettingsController;
use App\Models\EmergencyContact;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

/**
 * The "Show" / "Hide" button on an emergency contact. Showing needs a number.
 */
class UpdateEmergencyContactVisibilityRequest extends FormRequest
{
    /** @var string */
    protected $errorBag = 'emergencyContacts';

    public function authorize(): bool
    {
        return $this->user()?->isAdmin() ?? false;
    }

    /**
     * @return array<string, list<string>>
     */
    public function rules(): array
    {
        return [
            'is_visible' => ['required', 'boolean'],
        ];
    }

    /**
     * @return array<int, callable(Validator): void>
     */
    public function after(): array
    {
        return [function (Validator $validator): void {
            $contact = $this->route('emergencyContact');
            if (! $validator->errors()->has('is_visible') && $this->boolean('is_visible') && $contact instanceof EmergencyContact && blank($contact->phone)) {
                $validator->errors()->add('is_visible', "{$contact->name}: add a number before showing it to students.");
            }
        }];
    }

    protected function getRedirectUrl(): string
    {
        return SettingsController::tabUrl('emergency-contacts');
    }
}
