<?php

namespace App\Services;

use App\Enums\LoginChannel;
use App\Models\LoginActivity;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Laravel\Sanctum\PersonalAccessToken;

/**
 * Records sign-ins and sign-outs for the admin's sign-in history. Stores the time, IP address and
 * device only: never the password, the session id or the token itself.
 */
class LoginActivityLogger
{
    /**
     * Session key holding the id of the web sign-in, so the web logout closes the right row.
     */
    public const SESSION_KEY = 'login_activity_id';

    public function __construct(private DeviceDetector $devices) {}

    /**
     * Record a successful sign-in. For the mobile app, pass the new token (only its id is kept) and
     * the device name the app sent, used when the user agent isn't a recognised browser.
     */
    public function recordLogin(User $user, LoginChannel $channel, Request $request, ?PersonalAccessToken $token = null, ?string $deviceName = null): LoginActivity
    {
        $userAgent = $request->userAgent();

        $activity = new LoginActivity;
        $activity->forceFill([
            'user_id' => $user->id,
            'channel' => $channel,
            'ip_address' => $request->ip(),
            'user_agent' => $userAgent,
            'device' => Str::limit($this->devices->describe($userAgent) ?? $deviceName ?? '', 100, '') ?: null,
            'personal_access_token_id' => $token?->id,
            'logged_in_at' => now(),
        ])->save();

        // Online straight away, not only from the next request.
        $user->markSeen();

        return $activity;
    }

    public function recordWebLogout(Request $request): void
    {
        $this->close(LoginActivity::whereKey($request->session()->get(self::SESSION_KEY)));
    }

    public function recordTokenLogout(PersonalAccessToken $token): void
    {
        $this->close(LoginActivity::where('personal_access_token_id', $token->id));
    }

    /**
     * @param  Builder<LoginActivity>  $query
     */
    private function close(Builder $query): void
    {
        $query->whereNull('logged_out_at')->update(['logged_out_at' => now()]);
    }
}
