<?php

namespace App\Http\Controllers;

use App\Http\Requests\StorePushSubscriptionRequest;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use NotificationChannels\WebPush\PushSubscription;

/**
 * "Turn on emergency alerts": saves this browser's push subscription. Signed-in users' subscriptions are linked to
 * their account; guests' are anonymous. Turning alerts off deletes it.
 */
class PushSubscriptionController extends Controller
{
    public function store(StorePushSubscriptionRequest $request): JsonResponse
    {
        $endpoint = $request->string('endpoint')->toString();
        $keys = [
            'public_key' => $request->string('keys.p256dh')->toString(),
            'auth_token' => $request->string('keys.auth')->toString(),
            'content_encoding' => $request->input('content_encoding') ?? 'aes128gcm',
        ];

        if ($user = $request->user()) {
            $user->updatePushSubscription($endpoint, $keys['public_key'], $keys['auth_token'], $keys['content_encoding']);
        } else {
            // A guest only refreshes the keys; a subscription already linked to an account stays linked.
            PushSubscription::updateOrCreate(['endpoint' => $endpoint], $keys);
        }

        return response()->json(['subscribed' => true]);
    }

    public function destroy(Request $request): Response
    {
        $request->validate(['endpoint' => ['required', 'string']]);

        PushSubscription::where('endpoint', $request->string('endpoint')->toString())->delete();

        return response()->noContent();
    }
}
