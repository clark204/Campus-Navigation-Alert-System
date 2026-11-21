<?php

namespace App\Http\Requests\Admin;

use App\Enums\AlertSeverity;
use App\Enums\AlertType;
use App\Models\Alert;
use App\Services\CampusMap;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class StoreAlertRequest extends FormRequest
{
    public function authorize(): bool
    {
        // The admin, or a trusted faculty or staff member (their portal's Emergency alerts page).
        return $this->user()?->canSendAlerts() ?? false;
    }

    /**
     * A whole-campus alert sends no pin, so no radius either; affected buildings are optional.
     */
    protected function prepareForValidation(): void
    {
        if (blank($this->input('latitude')) && blank($this->input('longitude'))) {
            $this->merge(['danger_radius_m' => null]);
        }
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'type' => ['required', Rule::enum(AlertType::class)],
            ...UpdateAlertRequest::severityRules(),
            ...UpdateAlertRequest::wordingRules(),
            'is_drill' => ['boolean'],
            'latitude' => ['nullable', 'required_with:longitude', 'numeric', 'between:-90,90'],
            'longitude' => ['nullable', 'required_with:latitude', 'numeric', 'between:-180,180'],
            'danger_radius_m' => ['nullable', 'required_with:latitude', 'integer', Rule::in(Alert::DANGER_RADII)],
            'building_codes' => ['array'],
            'building_codes.*' => ['string', 'distinct', Rule::in(array_keys(app(CampusMap::class)->buildings()))],
        ];
    }

    /**
     * @return array<int, callable(Validator): void>
     */
    public function after(): array
    {
        return [function (Validator $validator) {
            if ($validator->errors()->hasAny(['latitude', 'longitude'])) {
                return;
            }

            $latitude = $this->input('latitude');
            $longitude = $this->input('longitude');
            if (filled($latitude) && ! app(CampusMap::class)->isNearCampus((float) $latitude, (float) $longitude)) {
                $validator->errors()->add('latitude', 'Put the pin on the campus map.');
            }
        }];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'type.required' => 'Choose the type of emergency.',
            'type.enum' => 'Choose the type of emergency.',
            'severity.required' => 'Choose how serious it is.',
            'severity.enum' => 'Choose how serious it is.',
            'danger_radius_m.in' => 'Choose a danger zone of 30, 50 or 100 m.',
            'danger_radius_m.required_with' => 'Choose a danger zone of 30, 50 or 100 m.',
            'building_codes.*.in' => 'Choose buildings from the map.',
            ...UpdateAlertRequest::wordingMessages(),
        ];
    }

    /**
     * The alert's attributes, with location fields normalised for Alert::send().
     *
     * @return array<string, mixed>
     */
    public function alertAttributes(): array
    {
        $hasPin = filled($this->input('latitude'));

        return [
            ...$this->safe()->only(['type', 'title', 'message', 'instructions']),
            'severity' => AlertSeverity::from((string) $this->input('severity')),
            'is_drill' => $this->boolean('is_drill'),
            'latitude' => $hasPin ? (float) $this->input('latitude') : null,
            'longitude' => $hasPin ? (float) $this->input('longitude') : null,
            'danger_radius_m' => $hasPin ? (int) $this->input('danger_radius_m') : null,
            'building_codes' => array_values($this->safe()->input('building_codes', [])),
        ];
    }
}
