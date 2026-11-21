<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\PasswordRecovery\ForgotPasswordRequest;
use App\Http\Requests\PasswordRecovery\ResetCodeRequest;
use App\Http\Requests\PasswordRecovery\ResetPasswordRequest;
use App\Services\EmailCodes;
use App\Services\PasswordRecovery;
use Illuminate\Http\JsonResponse;

/**
 * Password reset for the mobile app (docs/registration.md, "Password recovery"). Stateless: the app keeps the login,
 * then the reset grant, between calls.
 *
 * POST /api/password/forgot {login} → POST /api/password/verify-code {login, code} → {reset_token} →
 * POST /api/password/reset {reset_token, password, password_confirmation}.
 *
 * Someone who can't open their email goes to the administrator with their school ID; there is no API for that.
 */
class PasswordRecoveryController extends Controller
{
    public function __construct(private PasswordRecovery $recovery) {}

    public function forgot(ForgotPasswordRequest $request): JsonResponse
    {
        $this->recovery->requestReset(trim($request->validated('login')));

        return response()->json(['message' => PasswordRecovery::SENT_MESSAGE, 'resend_in' => EmailCodes::RESEND_SECONDS], 202);
    }

    /**
     * Step 1: the code on its own. A right code returns the reset grant for step 2.
     */
    public function verifyCode(ResetCodeRequest $request): JsonResponse
    {
        $token = $this->recovery->verifyResetCode($request->validated('login'), $request->validated('code'));

        return response()->json(['reset_token' => $token, 'expires_in' => PasswordRecovery::GRANT_TTL_MINUTES * 60]);
    }

    public function reset(ResetPasswordRequest $request): JsonResponse
    {
        $this->recovery->resetWithGrant($request->validated('reset_token'), $request->validated('password'));

        return response()->json(['message' => 'Password changed. Sign in with your new password.']);
    }
}
