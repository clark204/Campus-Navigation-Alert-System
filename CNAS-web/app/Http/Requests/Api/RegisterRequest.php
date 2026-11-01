<?php

namespace App\Http\Requests\Api;

use App\Http\Requests\Registration\RegistrationRules;
use Illuminate\Foundation\Http\FormRequest;

/**
 * POST /api/register. Student ID and college come from the COR draft; any sent here are ignored.
 * Name fields are optional and default to the draft's split.
 */
class RegisterRequest extends FormRequest
{
    use RegistrationRules;

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'draft_token' => ['required', 'string', 'size:40'],
            ...$this->nameRules(),
            ...$this->accountRules(),
        ];
    }
}
