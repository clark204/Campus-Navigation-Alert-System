<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * The open help request's check-in (HelpPingController): the person's latest position, or none when location is off.
 */
class HelpPingRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    /**
     * @return array<string, list<string>>
     */
    public function rules(): array
    {
        return NeedHelpRequest::locationRules();
    }

    /**
     * @return array{latitude: float, longitude: float, accuracy: float|null}|null
     */
    public function location(): ?array
    {
        return NeedHelpRequest::locationFrom($this->validated());
    }
}
