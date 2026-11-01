<?php

namespace App\Http\Requests\Registration;

/**
 * Web step 1: the COR plus the student's agreement to the Data Privacy Act notice.
 */
class UploadCorWithConsentRequest extends UploadCorRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [...parent::rules(), 'consent' => ['accepted']];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [...parent::messages(), 'consent.accepted' => 'Please agree to the data privacy notice to continue.'];
    }
}
