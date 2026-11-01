<?php

namespace App\Http\Requests\Registration;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Web step 2: email and password, plus the name fields if the student opened "Edit name".
 */
class CreateAccountRequest extends FormRequest
{
    use RegistrationRules;

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [...$this->nameRules(), ...$this->accountRules()];
    }
}
