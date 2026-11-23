<?php

namespace App\Http\Requests\Admin;

use App\Models\Concern;
use App\Services\ConcernInbox;
use Illuminate\Foundation\Http\FormRequest;

/**
 * The admin's reply to a concern (web inbox and mobile app). On the web its errors use the bag reply{id}, so they show
 * under that conversation's reply box.
 */
class ReplyConcernRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->isAdmin() ?? false;
    }

    protected function prepareForValidation(): void
    {
        /** @var Concern $concern */
        $concern = $this->route('concern');
        $this->errorBag = 'reply'.$concern->id;
    }

    /**
     * @return array<string, list<string>>
     */
    public function rules(): array
    {
        return [
            'message' => ['required', 'string', 'max:'.ConcernInbox::REPLY_MAX],
            'resolve' => ['nullable', 'boolean'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'message.required' => 'Write your reply first.',
            'message.max' => 'Keep the reply under '.number_format(ConcernInbox::REPLY_MAX).' characters.',
        ];
    }
}
