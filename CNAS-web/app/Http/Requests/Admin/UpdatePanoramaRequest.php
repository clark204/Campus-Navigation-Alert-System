<?php

namespace App\Http\Requests\Admin;

use App\Services\CampusMap;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

/**
 * A 360° photo's details: name, floor, position (dragged on the map), north (blank = not set) and whether users see
 * it. A photo can only be shown once north is set, because its arrows depend on it.
 */
class UpdatePanoramaRequest extends FormRequest
{
    public const NORTH_REQUIRED = 'Set north before showing this photo to users.';

    public function authorize(): bool
    {
        return $this->user()?->isAdmin() ?? false;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:100'],
            'floor' => ['required', 'integer', 'between:1,'.CampusMap::MAX_FLOORS],
            'latitude' => ['required', 'numeric', 'between:-90,90'],
            'longitude' => ['required', 'numeric', 'between:-180,180'],
            'north_offset' => ['nullable', 'numeric', 'between:0,360'],
            'is_visible' => ['required', 'boolean'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        $floor = 'Choose a floor from 1 to '.CampusMap::MAX_FLOORS.'.';
        $position = 'Drag the pin to where the photo was taken.';

        return [
            'name.required' => 'Enter a name.',
            'name.max' => 'Keep the name to 100 characters or fewer.',
            'floor.required' => $floor,
            'floor.integer' => $floor,
            'floor.between' => $floor,
            'latitude.*' => $position,
            'longitude.*' => $position,
            'north_offset.numeric' => 'North is a number of degrees from 0 to 360, or blank.',
            'north_offset.between' => 'North is a number of degrees from 0 to 360, or blank.',
        ];
    }

    /**
     * @return array<int, callable(Validator): void>
     */
    public function after(): array
    {
        return [function (Validator $validator): void {
            if ($validator->errors()->hasAny(['latitude', 'longitude', 'north_offset', 'is_visible'])) {
                return;
            }
            if (! app(CampusMap::class)->isNearCampus((float) $this->input('latitude'), (float) $this->input('longitude'))) {
                $validator->errors()->add('latitude', 'Place the pin on campus.');
            }
            if ($this->boolean('is_visible') && ! filled($this->input('north_offset'))) {
                $validator->errors()->add('is_visible', self::NORTH_REQUIRED);
            }
        }];
    }

    /**
     * The validated details, north rounded to 0.01° and turned into 0–359.99 (360 is north again).
     *
     * @return array<string, mixed>
     */
    public function panoramaAttributes(): array
    {
        $attributes = $this->validated();
        $attributes['north_offset'] = filled($attributes['north_offset'] ?? null)
            ? fmod(round((float) $attributes['north_offset'], 2), 360)
            : null;
        $attributes['is_visible'] = $this->boolean('is_visible');

        return $attributes;
    }
}
