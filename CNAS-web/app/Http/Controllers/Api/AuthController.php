<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\LoginRequest;
use App\Http\Resources\UserResource;
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
    public function login(LoginRequest $request, LoginService $logins): JsonResponse
    {
        $user = $logins->attempt($request->string('login')->toString(), $request->string('password')->toString());

        $token = $user->createToken($request->string('device_name')->trim()->toString());

        return response()->json([
            'token' => $token->plainTextToken,
            'user' => new UserResource($user->loadMissing('college')),
        ]);
    }

    public function me(Request $request): UserResource
    {
        return new UserResource($request->user()->loadMissing('college'));
    }

    /**
     * Revoke only the token used for this request; the user's other devices stay signed in.
     */
    public function logout(Request $request): Response
    {
        $request->user()->currentAccessToken()->delete();

        return response()->noContent();
    }
}
