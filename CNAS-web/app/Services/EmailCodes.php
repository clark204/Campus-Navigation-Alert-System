<?php

namespace App\Services;

use App\Enums\EmailCodePurpose;
use App\Mail\EmailCodeMail;
use App\Models\EmailCode;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Illuminate\Validation\ValidationException;

/**
 * 6-digit codes sent by email, shared by the web and the API: verifying the account's email, confirming a new email
 * in Settings, and password reset. One code per user and purpose; sending a new one replaces the old.
 *
 * Only a hash of the code is stored. A code works for TTL_MINUTES and allows MAX_ATTEMPTS wrong tries, after which
 * a new code is needed. A new code can be sent RESEND_SECONDS after the last one. Every error is on the key "code".
 */
class EmailCodes
{
    public const LENGTH = 6;

    public const TTL_MINUTES = 10;

    public const MAX_ATTEMPTS = 5;

    public const RESEND_SECONDS = 60;

    public const MISSING_MESSAGE = 'There is no code to check. Send a new one.';

    public const EXPIRED_MESSAGE = 'This code has expired. Send a new one.';

    public const LOCKED_MESSAGE = 'Too many wrong tries. Send a new code.';

    /**
     * Send a new code (to $email, or the account's email) and forget the previous one for this purpose.
     *
     * @throws ValidationException (key "code") within RESEND_SECONDS of the last code.
     */
    public function send(User $user, EmailCodePurpose $purpose, ?string $email = null): EmailCode
    {
        $wait = $this->secondsUntilResend($user, $purpose);

        if ($wait > 0) {
            throw ValidationException::withMessages([
                'code' => "Please wait {$wait} ".($wait === 1 ? 'second' : 'seconds').' before asking for a new code.',
            ]);
        }

        $email ??= $user->email;
        $code = str_pad((string) random_int(0, 10 ** self::LENGTH - 1), self::LENGTH, '0', STR_PAD_LEFT);

        $emailCode = DB::transaction(function () use ($user, $purpose, $email, $code) {
            EmailCode::where('user_id', $user->id)->where('purpose', $purpose)->delete();

            $emailCode = new EmailCode;
            $emailCode->forceFill([
                'user_id' => $user->id,
                'purpose' => $purpose,
                'email' => $email,
                'code_hash' => Hash::make($code),
                'attempts' => 0,
                'expires_at' => now()->addMinutes(self::TTL_MINUTES),
            ])->save();

            return $emailCode;
        });

        Mail::to($email)->queue(new EmailCodeMail($code, $purpose));

        return $emailCode;
    }

    /**
     * Send a code only when the user has none that can still be used (signing in again doesn't send another email).
     */
    public function sendUnlessPending(User $user, EmailCodePurpose $purpose): void
    {
        if ($this->usable($this->current($user, $purpose))) {
            return;
        }

        try {
            $this->send($user, $purpose);
        } catch (ValidationException) {
            // A code was sent moments ago; the user can ask for a new one on the verify screen.
        }
    }

    /**
     * Check a code. A right one is used up (deleted). A wrong one counts as a try.
     *
     * @return string The address the code was sent to (the new address for an email change).
     *
     * @throws ValidationException (key "code") when there is no code, it has expired, it is locked, or it is wrong.
     */
    public function verify(User $user, EmailCodePurpose $purpose, string $code): string
    {
        [$email, $error] = DB::transaction(function () use ($user, $purpose, $code) {
            $emailCode = EmailCode::where('user_id', $user->id)->where('purpose', $purpose)->lockForUpdate()->first();

            if ($emailCode === null) {
                return [null, self::MISSING_MESSAGE];
            }

            if ($emailCode->isExpired()) {
                return [null, self::EXPIRED_MESSAGE];
            }

            if ($emailCode->attempts >= self::MAX_ATTEMPTS) {
                return [null, self::LOCKED_MESSAGE];
            }

            if (! Hash::check($code, $emailCode->code_hash)) {
                $emailCode->increment('attempts');
                $left = self::MAX_ATTEMPTS - $emailCode->attempts;

                return [null, $left > 0
                    ? "That code is not right. You have {$left} ".($left === 1 ? 'try' : 'tries').' left.'
                    : self::LOCKED_MESSAGE];
            }

            $emailCode->delete();

            return [$emailCode->email, null];
        });

        if ($error !== null) {
            throw ValidationException::withMessages(['code' => $error]);
        }

        return $email;
    }

    /**
     * "Verify your email" (web and API): check the code and mark the account's email verified. The code only counts
     * for the address it was sent to, so it must still be the account's email.
     *
     * @throws ValidationException (key "code")
     */
    public function verifyAccountEmail(User $user, string $code): void
    {
        if ($this->verify($user, EmailCodePurpose::VerifyEmail, $code) !== $user->email) {
            throw ValidationException::withMessages(['code' => self::MISSING_MESSAGE]);
        }

        $user->markEmailAsVerified();
    }

    /**
     * The user's latest code for this purpose, used up or not (null after it was used or pruned).
     */
    public function current(User $user, EmailCodePurpose $purpose): ?EmailCode
    {
        return EmailCode::where('user_id', $user->id)->where('purpose', $purpose)->first();
    }

    /**
     * Not expired and not locked by wrong tries.
     */
    public function usable(?EmailCode $emailCode): bool
    {
        return $emailCode !== null && ! $emailCode->isExpired() && $emailCode->attempts < self::MAX_ATTEMPTS;
    }

    /**
     * 0 when a new code can be sent now.
     */
    public function secondsUntilResend(User $user, EmailCodePurpose $purpose): int
    {
        $sentAt = $this->current($user, $purpose)?->created_at;

        if ($sentAt === null) {
            return 0;
        }

        return max(0, (int) ceil(now()->diffInSeconds($sentAt->copy()->addSeconds(self::RESEND_SECONDS), false)));
    }

    /**
     * Throw away the user's code for this purpose (e.g. a cancelled email change).
     */
    public function forget(User $user, EmailCodePurpose $purpose): void
    {
        EmailCode::where('user_id', $user->id)->where('purpose', $purpose)->delete();
    }
}
