<?php

namespace App\Http\Requests\Admin;

use App\Http\Controllers\Admin\SettingsController;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Settings → Emergency contacts → move up or down (web and mobile app).
 */
class MoveEmergencyContactRequest extends FormRequest
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
        return ['direction' => ['required', Rule::in(['up', 'down'])]];
    }

    public function direction(): string
    {
        return (string) $this->validated('direction');
    }

    protected function getRedirectUrl(): string
    {
        return SettingsController::tabUrl('emergency-contacts');
    }
}
