<?php

namespace App\Http\Requests\Admin;

use App\Http\Controllers\Admin\SettingsController;
use App\Models\EmergencyContact;
use App\Rules\PhoneNumber;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Settings → Emergency contacts: adding one, or editing one. A contact can only be shown to students with a number.
 * Each form has its own error bag (errorBagFor) so an error shows on the form that was sent, not on every contact.
 */
class SaveEmergencyContactRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->isAdmin() ?? false;
    }

    /**
     * The error bag of the add form (no contact) or of one contact's edit form.
     */
    public static function errorBagFor(?EmergencyContact $contact): string
    {
        return $contact ? "emergencyContact{$contact->id}" : 'newEmergencyContact';
    }

    protected function prepareForValidation(): void
    {
        $contact = $this->route('emergencyContact');
        $this->errorBag = self::errorBagFor($contact instanceof EmergencyContact ? $contact : null);
    }

    /**
     * @return array<string, list<mixed>>
     */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:100'],
            'phone' => ['nullable', 'required_if_accepted:is_visible', 'string', 'max:30', new PhoneNumber],
            'note' => ['nullable', 'string', 'max:60'],
            'is_visible' => ['boolean'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'name.required' => 'Enter who students are calling, like Campus security.',
            'name.max' => 'Keep the name to 100 characters or fewer.',
            'phone.required_if_accepted' => 'Add a number before showing this contact to students.',
            'phone.max' => 'Keep the phone number to 30 characters or fewer.',
            'note.max' => 'Keep the note to 60 characters or fewer.',
        ];
    }

    /**
     * @return array{name: string, phone: string|null, note: string|null, is_visible: bool}
     */
    public function contact(): array
    {
        return [
            'name' => $this->validated('name'),
            'phone' => $this->validated('phone'),
            'note' => $this->validated('note'),
            'is_visible' => $this->boolean('is_visible'),
        ];
    }

    protected function getRedirectUrl(): string
    {
        return SettingsController::tabUrl('emergency-contacts');
    }
}
