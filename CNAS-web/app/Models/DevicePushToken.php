<?php

namespace App\Models;

use Database\Factories\DevicePushTokenFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * The mobile app's Expo push token (POST /api/push-tokens). Every token gets every alert push (SendExpoAlertPush),
 * guests included. user_id is only set from a signed-in request and never from the request body.
 *
 * @property string $expo_token
 * @property ?int $user_id
 * @property string $platform
 * @property ?string $device_name
 * @property Carbon $last_seen_at
 */
#[Fillable(['expo_token', 'platform', 'device_name', 'last_seen_at'])]
class DevicePushToken extends Model
{
    /** @use HasFactory<DevicePushTokenFactory> */
    use HasFactory;

    /** "ExponentPushToken[xxxx]" (and the newer "ExpoPushToken[xxxx]"). */
    public const FORMAT = '/^Expo(nent)?PushToken\[[A-Za-z0-9_-]+\]$/';

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'last_seen_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
