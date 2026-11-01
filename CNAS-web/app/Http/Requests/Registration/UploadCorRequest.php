<?php

namespace App\Http\Requests\Registration;

use App\Services\RegistrationService;
use Illuminate\Foundation\Http\FormRequest;

class UploadCorRequest extends FormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return ['cor' => RegistrationService::COR_RULES];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return RegistrationService::COR_MESSAGES;
    }
}
