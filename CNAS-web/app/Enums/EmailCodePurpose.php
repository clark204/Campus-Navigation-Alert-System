<?php

namespace App\Enums;

/**
 * What an emailed code is for (email_codes.purpose). Each user has at most one code per purpose.
 */
enum EmailCodePurpose: string
{
    case VerifyEmail = 'verify_email';
    case ChangeEmail = 'change_email';
    case ResetPassword = 'reset_password';

    public function subject(): string
    {
        return match ($this) {
            self::VerifyEmail => 'Your TCC NAV verification code',
            self::ChangeEmail => 'Confirm your new TCC NAV email',
            self::ResetPassword => 'Your TCC NAV password reset code',
        };
    }

    /**
     * The email's heading.
     */
    public function heading(): string
    {
        return match ($this) {
            self::VerifyEmail => 'Verify your email',
            self::ChangeEmail => 'Confirm your new email',
            self::ResetPassword => 'Reset your password',
        };
    }

    /**
     * The sentence above the code in the email.
     */
    public function intro(): string
    {
        return match ($this) {
            self::VerifyEmail => 'Enter this code on the “Verify your email” page in TCC NAV to finish setting up your account.',
            self::ChangeEmail => 'Enter this code in TCC NAV Settings to make this address your new sign-in email.',
            self::ResetPassword => 'Enter this code in TCC NAV to choose a new password.',
        };
    }
}
