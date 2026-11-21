<?php

namespace App\Http\Requests\Account;

use App\Services\EmailCodes;
use Illuminate\Foundation\Http\FormRequest;

/**
 * A 6-digit code from an email (App\Services\EmailCodes): "Verify your email" (web and API) and confirming a new
 * email in Settings. Spaces and dashes are ignored, so "123 456" works.
 */
class ConfirmEmailCodeRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    protected function prepareForValidation(): void
    {
        if (is_string($this->code)) {
            $this->merge(['code' => preg_replace('/[\s-]+/', '', $this->code)]);
        }
    }

    /**
     * @return array<string, list<string>>
     */
    public function rules(): array
    {
        return [
            'code' => ['required', 'string', 'digits:'.EmailCodes::LENGTH],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'code.required' => 'Enter the '.EmailCodes::LENGTH.'-digit code from the email.',
            'code.digits' => 'The code is '.EmailCodes::LENGTH.' digits, like 123456.',
        ];
    }
}
