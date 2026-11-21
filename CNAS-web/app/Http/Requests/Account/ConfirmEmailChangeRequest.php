<?php

namespace App\Http\Requests\Account;

/**
 * Settings → My account: the code sent to the new email. Errors go to the email form's bag and settings tab.
 */
class ConfirmEmailChangeRequest extends ConfirmEmailCodeRequest
{
    /** @var string */
    protected $errorBag = 'accountEmail';

    protected function getRedirectUrl(): string
    {
        return $this->user()->settingsUrl('my-account');
    }
}
