<?php

namespace App\Http\Requests\Api;

use App\Models\DevicePushToken;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * POST /api/push-tokens: the app's Expo push token, sent on every app start and after signing in.
 */
class StorePushTokenRequest extends FormRequest
{
    /**
     * @return array<string, list<mixed>>
     */
    public function rules(): array
    {
        return [
            'expo_token' => ['required', 'string', 'max:255', 'regex:'.DevicePushToken::FORMAT],
            'platform' => ['required', 'string', Rule::in(['android', 'ios'])],
            'device_name' => ['nullable', 'string', 'max:255'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return ['expo_token.regex' => 'This is not an Expo push token.'];
    }
}
