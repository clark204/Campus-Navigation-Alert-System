<?php

namespace App\Http\Controllers\Api;

use App\Enums\EmailCodePurpose;
use App\Enums\LoginChannel;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\LoginRequest;
use App\Http\Resources\UserResource;
use App\Services\EmailCodes;
use App\Services\LoginActivityLogger;
use App\Services\LoginService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/**
 * Sanctum token sign-in for the mobile app: one token per device, named after it.
 * Tokens expire after sanctum.expiration (30 days).
 */
class AuthController extends Controller
{
    public function login(LoginRequest $request, LoginService $logins, LoginActivityLogger $activity, EmailCodes $codes): JsonResponse
    {
        $user = $logins->attempt($request->string('login')->toString(), $request->string('password')->toString());

        $deviceName = $request->string('device_name')->trim()->toString();
        $token = $user->createToken($deviceName);

        $activity->recordLogin($user, LoginChannel::Api, $request, $token->accessToken, $deviceName);

        // Like the web: an unverified account gets a code, and the app shows its verify screen (email_verified false).
        if ($user->needsEmailVerification()) {
            $codes->sendUnlessPending($user, EmailCodePurpose::VerifyEmail);
        }

        return response()->json([
            'token' => $token->plainTextToken,
            'user' => new UserResource($user->loadMissing(['college', 'program'])),
        ]);
    }

    public function me(Request $request): UserResource
    {
        return new UserResource($request->user()->loadMissing(['college', 'program']));
    }

    /**
     * Revoke only the token used for this request; the user's other devices stay signed in.
     */
    public function logout(Request $request, LoginActivityLogger $activity): Response
    {
        $token = $request->user()->currentAccessToken();

        $activity->recordTokenLogout($token);
        $token->delete();

        return response()->noContent();
    }
}
