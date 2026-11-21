<?php

namespace App\Http\Requests\Account;

/**
 * "Verify your email" → "Wrong email?": fixes a typo in an email that was never verified. Same rules as the
 * Settings email; errors show on the verify page.
 */
class CorrectUnverifiedEmailRequest extends UpdateEmailRequest
{
    /** @var string */
    protected $errorBag = 'default';

    protected function getRedirectUrl(): string
    {
        return route('email.verify');
    }
}
