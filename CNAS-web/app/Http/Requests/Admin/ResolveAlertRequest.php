<?php

namespace App\Http\Requests\Admin;

use App\Enums\AlertResolution;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class ResolveAlertRequest extends FormRequest
{
    public function authorize(): bool
    {
        // The admin, or a trusted faculty or staff member (their portal's Emergency alerts page).
        return $this->user()?->canSendAlerts() ?? false;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'resolution' => ['required', Rule::enum(AlertResolution::class)],
            'resolved_message' => ['required', 'string', 'max:500'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'resolution.required' => 'Choose "All clear" or "False alarm".',
            'resolution.enum' => 'Choose "All clear" or "False alarm".',
            'resolved_message.required' => 'Tell everyone what to do now, e.g. that it is safe to go back inside.',
        ];
    }

    public function resolution(): AlertResolution
    {
        return $this->enum('resolution', AlertResolution::class);
    }
}
