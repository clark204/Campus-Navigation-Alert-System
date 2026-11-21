<?php

namespace App\Http\Requests\Admin;

use App\Enums\AlertSeverity;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateAlertRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->isAdmin() ?? false;
    }

    /**
     * The text everyone sees in the banner, shared with StoreAlertRequest.
     *
     * @return array<string, list<string>>
     */
    public static function wordingRules(): array
    {
        return [
            'title' => ['required', 'string', 'max:120'],
            'message' => ['required', 'string', 'max:1000'],
            'instructions' => ['nullable', 'string', 'max:2000'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public static function wordingMessages(): array
    {
        return [
            'title.required' => 'Give the alert a short title.',
            'message.required' => 'Say what is happening.',
        ];
    }

    /**
     * @return array<string, mixed>
     */
    /**
     * The level (advisory, warning or emergency) is part of the same form, so it is validated here too.
     *
     * @return array<string, list<string>>
     */
    public static function severityRules(): array
    {
        return ['severity' => ['required', Rule::enum(AlertSeverity::class)]];
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [...self::wordingRules(), ...self::severityRules()];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [...self::wordingMessages(), 'severity.required' => 'Choose how serious it is.', 'severity.enum' => 'Choose how serious it is.'];
    }
}
