<?php

namespace App\Http\Requests;

use App\Enums\ConcernTopic;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * A concern, suggestion or feedback from Help & contact (signed in: the reply goes to the account's email) or the
 * site footer (guest: they type their email).
 */
class StoreConcernRequest extends FormRequest
{
    /** @var string */
    protected $errorBag = 'concern';

    public const MESSAGE_MAX = 2000;

    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        if (is_string($this->email)) {
            $this->merge(['email' => strtolower(trim($this->email))]);
        }
    }

    /**
     * @return array<string, list<mixed>>
     */
    public function rules(): array
    {
        return [
            'topic' => ['required', Rule::enum(ConcernTopic::class)],
            'message' => ['required', 'string', 'min:10', 'max:'.self::MESSAGE_MAX],
            // Signed in: the reply goes to the account's email, whatever the form sent.
            'email' => $this->user() ? [] : ['required', 'string', 'email:rfc,strict', 'max:255'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'topic.required' => 'Choose what your message is about.',
            'topic.enum' => 'Choose what your message is about.',
            'message.required' => 'Write your message.',
            'message.min' => 'Tell us a little more, at least 10 characters.',
            'message.max' => 'Keep your message under '.number_format(self::MESSAGE_MAX).' characters.',
            'email.required' => 'Enter your email so we can reply.',
            'email.email' => 'Enter a real email address, like name@example.com.',
        ];
    }

    /**
     * Back to the form: the portal's Help & contact page, or the site footer's form (#feedback) for a guest.
     */
    protected function getRedirectUrl(): string
    {
        return self::backToForm($this->user() !== null);
    }

    public static function backToForm(bool $signedIn): string
    {
        return url()->previous().($signedIn ? '' : '#feedback');
    }
}
