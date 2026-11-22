<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\StorePushTokenRequest;
use App\Models\DevicePushToken;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/**
 * The mobile app's alert push (docs/alerts.md, "Phone push"). Public like web push: guests get alerts too. A valid
 * Sanctum bearer token links the phone to that account; a guest call never unlinks a token an account owns (same
 * rule as PushSubscriptionController). Turning alerts off in the app deletes it.
 */
class PushTokenController extends Controller
{
    public function store(StorePushTokenRequest $request): JsonResponse
    {
        $token = DevicePushToken::firstOrNew(['expo_token' => $request->validated('expo_token')]);
        $token->fill([
            'platform' => $request->validated('platform'),
            'device_name' => $request->validated('device_name'),
            'last_seen_at' => now(),
        ]);

        // The route has no auth middleware, so an invalid or expired bearer token simply counts as a guest.
        if ($user = $request->user('sanctum')) {
            $token->user()->associate($user);
        }

        $token->save();

        return response()->json(['subscribed' => true, 'linked' => $token->user_id !== null]);
    }

    public function destroy(Request $request): Response
    {
        $request->validate(['expo_token' => ['required', 'string', 'max:255']]);

        DevicePushToken::where('expo_token', $request->string('expo_token')->toString())->delete();

        return response()->noContent();
    }
}
