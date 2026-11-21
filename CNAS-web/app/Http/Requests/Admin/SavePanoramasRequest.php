<?php

namespace App\Http\Requests\Admin;

use App\Services\CampusMap;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

/**
 * Several photos' details saved at once from the map editor ("Save all", or one photo's "Save"). Every photo is checked
 * with the same rules as a single photo (UpdatePanoramaRequest); if any fails, nothing is saved.
 */
class SavePanoramasRequest extends FormRequest
{
    public const MAX_PHOTOS = 50;

    public function authorize(): bool
    {
        return $this->user()?->isAdmin() ?? false;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $photo = [
            'code' => ['required', 'string', 'distinct', 'exists:panoramas,code'],
            'name' => ['required', 'string', 'max:100'],
            'floor' => ['required', 'integer', 'between:1,'.CampusMap::MAX_FLOORS],
            'latitude' => ['required', 'numeric', 'between:-90,90'],
            'longitude' => ['required', 'numeric', 'between:-180,180'],
            'north_offset' => ['nullable', 'numeric', 'between:0,360'],
            'is_visible' => ['required', 'boolean'],
        ];

        $rules = ['photos' => ['required', 'array', 'min:1', 'max:'.self::MAX_PHOTOS]];
        foreach ($photo as $field => $fieldRules) {
            $rules["photos.*.{$field}"] = $fieldRules;
        }

        return $rules;
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        $floor = 'Choose a floor from 1 to '.CampusMap::MAX_FLOORS.'.';
        $position = 'Drag the pin to where the photo was taken.';

        return [
            'photos.required' => 'Choose at least one photo to save.',
            'photos.*.code.distinct' => 'A photo is listed more than once.',
            'photos.*.code.exists' => 'A photo was not found. Reload the page and try again.',
            'photos.*.name.required' => 'Enter a name for every photo.',
            'photos.*.name.max' => 'Keep each name to 100 characters or fewer.',
            'photos.*.floor.required' => $floor,
            'photos.*.floor.integer' => $floor,
            'photos.*.floor.between' => $floor,
            'photos.*.latitude.*' => $position,
            'photos.*.longitude.*' => $position,
            'photos.*.north_offset.numeric' => 'North is a number of degrees from 0 to 360, or blank.',
            'photos.*.north_offset.between' => 'North is a number of degrees from 0 to 360, or blank.',
        ];
    }

    /**
     * @return array<int, callable(Validator): void>
     */
    public function after(): array
    {
        return [function (Validator $validator): void {
            $campus = app(CampusMap::class);
            foreach ($this->input('photos', []) as $index => $photo) {
                if (! is_array($photo)) {
                    continue;
                }
                $errors = $validator->errors();
                $position = "photos.{$index}.latitude";
                if (! $errors->hasAny(["photos.{$index}.latitude", "photos.{$index}.longitude"])
                    && ! $campus->isNearCampus((float) $photo['latitude'], (float) $photo['longitude'])) {
                    $errors->add($position, 'Place the pin on campus.');
                }
                if (! $errors->hasAny(["photos.{$index}.is_visible", "photos.{$index}.north_offset"])
                    && ! empty($photo['is_visible']) && ! filled($photo['north_offset'] ?? null)) {
                    $errors->add("photos.{$index}.is_visible", UpdatePanoramaRequest::NORTH_REQUIRED);
                }
            }
        }];
    }

    /**
     * The photos to save, keyed by code: north rounded to 0.01° and turned into 0–359.99, visibility as a boolean.
     *
     * @return array<string, array<string, mixed>>
     */
    public function photoAttributes(): array
    {
        $attributes = [];
        foreach ($this->validated('photos') as $photo) {
            $attributes[$photo['code']] = [
                'name' => trim($photo['name']),
                'floor' => (int) $photo['floor'],
                'latitude' => (float) $photo['latitude'],
                'longitude' => (float) $photo['longitude'],
                'north_offset' => filled($photo['north_offset'] ?? null) ? fmod(round((float) $photo['north_offset'], 2), 360) : null,
                'is_visible' => filter_var($photo['is_visible'], FILTER_VALIDATE_BOOLEAN),
            ];
        }

        return $attributes;
    }
}
