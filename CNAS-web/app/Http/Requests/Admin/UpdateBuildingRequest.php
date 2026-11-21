<?php

namespace App\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Editing a building's name and landmark name. Everything else about a building comes from the other app.
 */
class UpdateBuildingRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->isAdmin() ?? false;
    }

    /**
     * @return array<string, list<string>>
     */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:100'],
            'landmark_name' => ['nullable', 'string', 'max:60'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'name.required' => 'Enter the name students see.',
            'name.max' => 'Keep the name to 100 characters or fewer.',
            'landmark_name.max' => 'Keep the landmark name to 60 characters or fewer.',
        ];
    }
}
