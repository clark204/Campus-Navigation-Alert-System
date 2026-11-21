<?php

namespace App\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;

class ImportCampusMapRequest extends FormRequest
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
        return ['file' => ['required', 'file', 'extensions:geojson', 'max:20480']];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'file.required' => 'Choose the campus.geojson file to import.',
            'file.extensions' => 'The map must be a .geojson file.',
            'file.max' => 'The campus.geojson file must be 20 MB or smaller.',
        ];
    }
}
