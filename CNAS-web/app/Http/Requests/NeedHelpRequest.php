<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * "I need help" during an emergency (web takeover and banner, mobile app): an optional note for the administrators.
 */
class NeedHelpRequest extends FormRequest
{
    public const NOTE_MAX = 200;

    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    /**
     * @return array<string, list<string>>
     */
    public function rules(): array
    {
        return ['note' => ['nullable', 'string', 'max:'.self::NOTE_MAX]];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return ['note.max' => 'Keep it short: '.self::NOTE_MAX.' characters or fewer.'];
    }
}
