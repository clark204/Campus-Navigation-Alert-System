<?php

namespace App\Http\Requests\Admin;

use App\Enums\Semester;
use App\Http\Controllers\Admin\SettingsController;
use App\Rules\AcademicYear;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Settings → Academic term → Add a term (web and mobile app). Errors use the bag academicTerm on the web.
 */
class StoreAcademicTermRequest extends FormRequest
{
    /** @var string */
    protected $errorBag = 'academicTerm';

    protected function getRedirectUrl(): string
    {
        return SettingsController::tabUrl('academic-term');
    }

    public function authorize(): bool
    {
        return $this->user()?->isAdmin() ?? false;
    }

    protected function prepareForValidation(): void
    {
        if (is_string($this->academic_year)) {
            $this->merge(['academic_year' => preg_replace('/\s+/', '', $this->academic_year)]);
        }
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'academic_year' => [
                'required', 'string', new AcademicYear,
                Rule::unique('academic_terms')->where('semester', $this->input('semester')),
            ],
            'semester' => ['required', Rule::enum(Semester::class)],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'academic_year.unique' => 'That term already exists.',
            'semester.required' => 'Choose a semester.',
            'semester.enum' => 'Choose a semester.',
        ];
    }
}
