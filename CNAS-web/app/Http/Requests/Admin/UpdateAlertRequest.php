<?php

namespace App\Http\Requests\Admin;

use App\Enums\AlertSeverity;
use App\Models\Alert;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateAlertRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->isAdmin() ?? false;
    }

    /**
     * The text everyone sees, shared with StoreAlertRequest. Short on purpose: the title fits two lines of the takeover
     * and "What to do now" is one sentence, required for an emergency (the takeover's bold line). The full message and
     * longer instructions go under "More details".
     *
     * @return array<string, list<string>>
     */
    public static function wordingRules(): array
    {
        return [
            'title' => ['required', 'string', 'max:'.Alert::TITLE_MAX],
            'message' => ['required', 'string', 'max:1000'],
            'what_to_do' => ['nullable', 'required_if:severity,emergency', 'string', 'max:'.Alert::WHAT_TO_DO_MAX],
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
            'title.max' => 'Keep the title to '.Alert::TITLE_MAX.' characters so it fits on a phone.',
            'message.required' => 'Say what is happening.',
            'what_to_do.required_if' => 'Say in one short sentence what people must do now.',
            'what_to_do.max' => 'Keep "What to do now" to '.Alert::WHAT_TO_DO_MAX.' characters: one short sentence.',
        ];
    }

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
