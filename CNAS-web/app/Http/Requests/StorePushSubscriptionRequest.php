<?php

namespace App\Http\Requests;

use Closure;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use NotificationChannels\WebPush\PushSubscription;

/**
 * A PushSubscription.toJSON() from the browser: { endpoint, keys: { p256dh, auth } }, plus the content encoding.
 * Open to guests: anyone may turn on emergency alerts.
 */
class StorePushSubscriptionRequest extends FormRequest
{
    /**
     * The server POSTs to the endpoint, so only real push services are accepted (no requests to internal hosts).
     * Chrome/Edge/Android (FCM), Firefox (Mozilla), Safari/iOS (Apple) and Windows (WNS).
     */
    public const PUSH_SERVICE_HOSTS = [
        'fcm.googleapis.com',
        'android.googleapis.com',
        '*.push.services.mozilla.com',
        'push.services.mozilla.com',
        '*.push.apple.com',
        '*.notify.windows.com',
    ];

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'endpoint' => ['required', 'string', 'url:https', 'max:'.PushSubscription::ENDPOINT_MAX_LENGTH, $this->knownPushService(...)],
            'keys.p256dh' => ['required', 'string', 'max:255'],
            'keys.auth' => ['required', 'string', 'max:255'],
            'content_encoding' => ['nullable', Rule::in(['aes128gcm', 'aesgcm'])],
        ];
    }

    private function knownPushService(string $attribute, mixed $value, Closure $fail): void
    {
        $host = Str::lower((string) parse_url((string) $value, PHP_URL_HOST));

        if (! Str::is(self::PUSH_SERVICE_HOSTS, $host)) {
            $fail('This browser’s push service isn’t supported.');
        }
    }
}
