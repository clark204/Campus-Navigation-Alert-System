<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Account\ConfirmEmailCodeRequest;
use App\Http\Requests\Account\UpdateEmailRequest;
use App\Http\Requests\Api\UpdatePasswordRequest;
use App\Http\Resources\UserResource;
use App\Models\User;
use App\Services\AccountChanges;
use App\Services\UserSessions;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Laravel\Sanctum\PersonalAccessToken;

/**
 * The mobile app's Change email and Change password, like Settings → My account on the web (AccountController): the
 * same requests, rules, messages and rate limits (AccountChanges, docs/registration.md "Email verification"). Errors
 * are 422 on "code" or "email"; every answer has the message to show.
 */
class AccountController extends Controller
{
    public function __construct(private AccountChanges $changes) {}

    /**
     * The email and the change waiting for its code (null when none): `pending.email` is the new address, and
     * `pending.resend_in` the seconds before another code can be sent.
     */
    public function email(Request $request): JsonResponse
    {
        return response()->json($this->emailState($request->user()));
    }

    public function updateEmail(UpdateEmailRequest $request): JsonResponse
    {
        $user = $request->user();
        $message = $this->changes->requestEmailChange($user, $request->validated('email'));

        return response()->json(['message' => $message, ...$this->emailState($user)], $this->changes->pendingEmailChange($user) ? 202 : 200);
    }

    /**
     * Answers with the user (the new email, verified).
     */
    public function confirmEmail(ConfirmEmailCodeRequest $request): JsonResponse
    {
        $user = $request->user();
        $message = $this->changes->confirmEmailChange($user, $request->validated('code'));

        return response()->json(['message' => $message, 'user' => new UserResource($user->loadMissing(['college', 'program']))]);
    }

    public function resendEmailCode(Request $request): JsonResponse
    {
        $user = $request->user();
        $message = $this->changes->resendEmailChangeCode($user);

        return response()->json(['message' => $message, ...$this->emailState($user)], 202);
    }

    public function cancelEmail(Request $request): JsonResponse
    {
        $user = $request->user();

        return response()->json(['message' => $this->changes->cancelEmailChange($user), ...$this->emailState($user)]);
    }

    /**
     * Like the web: every other device is signed out (web sessions and the other phones' tokens). This phone keeps its
     * token.
     */
    public function updatePassword(UpdatePasswordRequest $request, UserSessions $sessions): JsonResponse
    {
        $user = $request->user();
        $token = $user->currentAccessToken();

        $this->changes->changePassword($user, $request->validated('password'));
        $sessions->endAll($user, keepTokenId: $token instanceof PersonalAccessToken ? $token->getKey() : null);

        return response()->json([
            'message' => 'Password changed. You were signed out everywhere else, including the website.',
            'user' => new UserResource($user->loadMissing(['college', 'program'])),
        ]);
    }

    /**
     * @return array{email: string, email_verified: bool, pending: array{email: string, resend_in: int}|null}
     */
    private function emailState(User $user): array
    {
        $pending = $this->changes->pendingEmailChange($user);

        return [
            'email' => $user->email,
            'email_verified' => ! $user->needsEmailVerification(),
            'pending' => $pending === null ? null : [
                'email' => $pending->email,
                'resend_in' => $this->changes->secondsUntilResend($user),
            ],
        ];
    }
}
