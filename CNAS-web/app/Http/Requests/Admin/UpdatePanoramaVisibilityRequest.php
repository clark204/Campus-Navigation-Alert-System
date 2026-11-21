<?php

namespace App\Http\Requests\Admin;

use App\Models\Panorama;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

/**
 * The "Show to users" / "Hide" toggle on the 360° photos list. Showing needs north to be set.
 */
class UpdatePanoramaVisibilityRequest extends FormRequest
{
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
            'is_visible' => ['required', 'boolean'],
        ];
    }

    /**
     * @return array<int, callable(Validator): void>
     */
    public function after(): array
    {
        return [function (Validator $validator): void {
            $panorama = $this->route('panorama');
            if (! $validator->errors()->has('is_visible') && $this->boolean('is_visible') && $panorama instanceof Panorama && ! $panorama->hasNorth()) {
                $validator->errors()->add('is_visible', "{$panorama->name}: ".UpdatePanoramaRequest::NORTH_REQUIRED);
            }
        }];
    }
}
