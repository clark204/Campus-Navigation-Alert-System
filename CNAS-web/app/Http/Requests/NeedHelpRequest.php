<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * "I need help" during an emergency (web takeover and banner, mobile app): an optional note for the administrators and,
 * when the person shares it, where their phone or browser says they are. HelpPingRequest takes the same location.
 */
class NeedHelpRequest extends FormRequest
{
    public const NOTE_MAX = 200;

    /** GPS accuracy (metres) above this is refused: it would put the person anywhere on campus. */
    public const ACCURACY_MAX = 5000;

    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    /**
     * @return array<string, list<string>>
     */
    public function rules(): array
    {
        return [
            'note' => ['nullable', 'string', 'max:'.self::NOTE_MAX],
            ...self::locationRules(),
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return ['note.max' => 'Keep it short: '.self::NOTE_MAX.' characters or fewer.'];
    }

    /**
     * @return array<string, list<string>>
     */
    public static function locationRules(): array
    {
        return [
            'latitude' => ['nullable', 'required_with:longitude', 'numeric', 'between:-90,90'],
            'longitude' => ['nullable', 'required_with:latitude', 'numeric', 'between:-180,180'],
            'accuracy' => ['nullable', 'numeric', 'min:0', 'max:'.self::ACCURACY_MAX],
        ];
    }

    /**
     * @return array{latitude: float, longitude: float, accuracy: float|null}|null
     */
    public function location(): ?array
    {
        return self::locationFrom($this->validated());
    }

    /**
     * @param  array<string, mixed>  $validated
     * @return array{latitude: float, longitude: float, accuracy: float|null}|null
     */
    public static function locationFrom(array $validated): ?array
    {
        if (! isset($validated['latitude'], $validated['longitude'])) {
            return null;
        }

        return [
            'latitude' => (float) $validated['latitude'],
            'longitude' => (float) $validated['longitude'],
            'accuracy' => isset($validated['accuracy']) ? (float) $validated['accuracy'] : null,
        ];
    }
}
