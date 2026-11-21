<?php

namespace App\Http\Controllers\Api;

use App\Enums\EmailCodePurpose;
use App\Http\Controllers\Controller;
use App\Http\Requests\Account\ConfirmEmailCodeRequest;
use App\Http\Resources\UserResource;
use App\Services\EmailCodes;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * "Verify your email" for the mobile app (Sanctum token). The app shows its verify screen while the user's
 * `email_verified` is false (UserResource). Same codes and rules as the web (EmailCodes).
 */
class EmailVerificationController extends Controller
{
    public function __construct(private EmailCodes $codes) {}

    /**
     * Check the code. Answers with the user (email_verified true); 422 on "code" when it is wrong, expired or locked.
     */
    public function verify(ConfirmEmailCodeRequest $request): UserResource
    {
        $user = $request->user();

        if ($user->needsEmailVerification()) {
            $this->codes->verifyAccountEmail($user, $request->validated('code'));
        }

        return new UserResource($user->loadMissing(['college', 'program']));
    }

    /**
     * Send a new code. 422 on "code" within EmailCodes::RESEND_SECONDS of the last one.
     */
    public function resend(Request $request): JsonResponse
    {
        $user = $request->user();

        if (! $user->needsEmailVerification()) {
            return response()->json(['message' => 'Your email is already verified.', 'resend_in' => 0]);
        }

        $this->codes->send($user, EmailCodePurpose::VerifyEmail);

        return response()->json([
            'message' => "We sent a new code to {$user->email}.",
            'resend_in' => EmailCodes::RESEND_SECONDS,
        ], 202);
    }
}
