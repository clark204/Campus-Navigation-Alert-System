<?php

namespace App\Providers;

use App\Enums\EmailCodePurpose;
use App\Enums\RoomType;
use App\Enums\SafetyStatus;
use App\Models\Alert;
use App\Models\EmergencyContact;
use App\Models\Room;
use App\Models\SafetyCheck;
use App\Services\ContactDetails;
use App\Services\EmailCodes;
use App\Services\PasswordRecovery;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\View;
use Illuminate\Support\ServiceProvider;
use Illuminate\Support\Str;
use Illuminate\Validation\Rules\Password;
use Illuminate\View\View as ViewInstance;
use Symfony\Component\HttpFoundation\Response;

class AppServiceProvider extends ServiceProvider
{
    /** Saved and recent places listed on the portal home; the rest are on the Saved places page. */
    public const HOME_PLACES = 5;

    /** Notifications listed in the top bar bell (newest first). */
    public const BELL_NOTIFICATIONS = 8;

    /** The College of Information Technology (colleges.id): its users see the map's IT rooms on the portal home. */
    public const IT_COLLEGE = 'C01';

    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        $this->configureRateLimiting();

        // Password::defaults() (the admin's "Change password"): the same strength registration asks for.
        Password::defaults(fn () => Password::min(8)->letters()->numbers());

        // Site footer and portal Help & contact page: the contact details the admin saved on /admin/settings.
        View::composer('partials.contact-details', fn (ViewInstance $view) => $view->with('contact', app(ContactDetails::class)->all()));

        // Portal home: the emergency numbers the admin shows on /admin/settings (none = the block is hidden).
        View::composer('portal.dashboard-page', fn (ViewInstance $view) => $view->with('emergencyContacts', EmergencyContact::listed()));

        // Portal home (both portals): the signed-in user's newest saved and recent places, the office rooms on the map,
        // and for an IT college user the map's IT rooms (rooms.is_it) by building. All real data, no placeholders.
        View::composer('portal.dashboard-page', function (ViewInstance $view) {
            $user = request()->user();
            $view->with([
                'savedPlaces' => $user->savedRooms()->with('building')->limit(self::HOME_PLACES)->get(),
                'recentPlaces' => $user->recentRooms()->with('building')->limit(self::HOME_PLACES)->get(),
                'offices' => Room::query()->where('type', RoomType::Office)->with('building')->orderBy('name')->get(),
                'collegeRooms' => $user->college?->id === self::IT_COLLEGE
                    ? Room::query()->where('is_it', true)->with('building')->orderBy('name')->get()
                        ->sortBy(fn (Room $room) => $room->locationLabel().' '.$room->name, SORT_NATURAL | SORT_FLAG_CASE)
                        ->groupBy(fn (Room $room) => $room->locationLabel())
                    : collect(),
            ]);
        });

        // Settings → My account → Email (admin and portals): a pending email change waits for the code sent to it.
        View::composer('partials.account-email-form', function (ViewInstance $view) {
            $codes = app(EmailCodes::class);
            $user = request()->user();
            $emailChange = $codes->current($user, EmailCodePurpose::ChangeEmail);

            $view->with([
                'emailChange' => $emailChange,
                'emailChangeUsable' => $codes->usable($emailChange),
                'emailChangeResendIn' => $emailChange ? $codes->secondsUntilResend($user, EmailCodePurpose::ChangeEmail) : 0,
            ]);
        });

        // The notification bell in the top bar (admin and portals): the unread count and the newest few.
        View::composer('partials.notification-bell', function (ViewInstance $view) {
            $user = request()->user();

            $view->with([
                'user' => $user,
                'unreadCount' => $user->unreadNotifications()->count(),
                'notifications' => $user->notifications()->limit(self::BELL_NOTIFICATIONS)->get(),
            ]);
        });

        // The emergency banner and alarm takeover are on every page (the layouts include them).
        View::composer('partials.alerts', function (ViewInstance $view) {
            $alert = Alert::current();
            $user = request()->user();

            // This user's answer for the active alert: "I'm safe" or "I need help" (null when none).
            $answer = $user && $alert?->isActive()
                ? SafetyCheck::where('alert_id', $alert->id)->where('user_id', $user->id)->value('status')
                : null;

            $view->with([
                'alert' => $alert,
                // "I'm safe" and "I need help" are only asked during an emergency (no check-in for other levels).
                'canMarkSafe' => $user !== null && $alert?->isEmergency() === true,
                'markedSafe' => $answer === SafetyStatus::Safe,
                'askedForHelp' => $answer === SafetyStatus::NeedHelp,
            ]);
        });
    }

    private function configureRateLimiting(): void
    {
        // Registration (web and API): short burst limit plus an hourly cap, per IP.
        // COR uploads get their own bucket so a student who re-uploads isn't locked out of the last step.
        RateLimiter::for('register-cor', fn (Request $request) => $this->perIpLimits(
            $request, 'register-cor', 'Too many COR uploads.',
        ));

        RateLimiter::for('register', fn (Request $request) => $this->perIpLimits(
            $request, 'register', 'Too many sign-up attempts.',
        ));

        // Emailed codes (EmailCodes): sending and checking, per user and per IP. EmailCodes itself already waits
        // RESEND_SECONDS between codes and locks a code after MAX_ATTEMPTS wrong tries; these stop anyone working
        // around that with many codes or many accounts. The per-IP caps are high: the whole campus can share one IP.
        // The Settings email change has its own buckets so its message shows on that form (accountEmail bag).
        $emailCodeLimits = [
            'email-code-send' => [10, 'hour', 30, 'Too many codes asked for.'],
            'email-code-verify' => [10, 'minute', 60, 'Too many tries.'],
        ];
        foreach ($emailCodeLimits as $name => [$perUser, $userWindow, $perIpPerMinute, $message]) {
            foreach (['' => 'default', 'settings-' => 'accountEmail'] as $prefix => $bag) {
                RateLimiter::for($prefix.$name, fn (Request $request) => $this->perUserAndIpLimits(
                    $request, $prefix.$name, $perUser, $userWindow, $perIpPerMinute, $message, $bag,
                ));
            }
        }

        RateLimiter::for('login', fn (Request $request) => Limit::perMinute(5)
            ->by(Str::transliterate(Str::lower($request->string('login')->trim()).'|'.$request->ip()))
            ->response(fn (Request $request, array $headers) => $this->tooManyAttempts(
                $request, $headers, 'Too many sign-in attempts.', 'login',
            )));

        // The app's push token (POST/DELETE /api/push-tokens), sent on every app start. Per token, plus a high per-IP
        // ceiling: the whole campus can share one IP.
        RateLimiter::for('push-tokens', fn (Request $request) => [
            Limit::perMinute(10)->by('push-tokens:token:'.hash('sha256', (string) $request->input('expo_token'))),
            Limit::perMinute(300)->by('push-tokens:ip:'.$request->ip()),
        ]);

        // Password recovery (PasswordRecovery). Per login (email or student ID: the API sends it, the web reset page
        // keeps it in the session) and per IP. The per-IP caps are high on purpose: the whole campus can share one IP.
        RateLimiter::for('password-forgot', fn (Request $request) => $this->perKeyAndIpLimits(
            $request, 'password-forgot', $this->recoveryLogin($request), Limit::perHour(5), 30, 'Too many reset codes asked for.', 'login',
        ));
        // Step 1 (the code) per login; step 2 (the new password) per reset grant, which the API sends instead of a login.
        RateLimiter::for('password-reset', fn (Request $request) => $this->perKeyAndIpLimits(
            $request, 'password-reset', $this->recoveryLogin($request) ?: $this->fromRequestOrSession($request, 'reset_token', PasswordRecovery::SESSION_GRANT),
            Limit::perMinute(10), 60, 'Too many tries.', 'code',
        ));
    }

    /**
     * A value the API sends as $input and the web keeps in the session under $sessionKey.
     */
    private function fromRequestOrSession(Request $request, string $input, string $sessionKey): string
    {
        $value = $request->input($input) ?? ($request->hasSession() ? $request->session()->get($sessionKey) : null);

        return trim((string) $value);
    }

    /**
     * The email or student ID a password reset is for: from the request (API, "Forgot password?") or from the session
     * (the web reset page).
     */
    private function recoveryLogin(Request $request): string
    {
        return Str::transliterate(Str::lower($this->fromRequestOrSession($request, 'login', PasswordRecovery::SESSION_LOGIN)));
    }

    /**
     * One limit keyed by $key (a login or a recovery token) and a per-minute cap per IP.
     *
     * @return list<Limit>
     */
    private function perKeyAndIpLimits(Request $request, string $name, string $key, Limit $perKey, int $perIpPerMinute, string $message, string $errorKey): array
    {
        $tooMany = fn (Request $request, array $headers) => $this->tooManyAttempts($request, $headers, $message, $errorKey);

        return [
            $perKey->by("{$name}:key:".hash('sha256', $key))->response($tooMany),
            Limit::perMinute($perIpPerMinute)->by("{$name}:ip:".$request->ip())->response($tooMany),
        ];
    }

    /**
     * @return list<Limit>
     */
    private function perIpLimits(Request $request, string $name, string $message): array
    {
        $tooMany = fn (Request $request, array $headers) => $this->tooManyAttempts($request, $headers, $message);

        return [
            Limit::perMinute(5)->by("{$name}:minute:".$request->ip())->response($tooMany),
            Limit::perHour(20)->by("{$name}:hour:".$request->ip())->response($tooMany),
        ];
    }

    /**
     * @param  'minute'|'hour'  $userWindow
     * @return list<Limit>
     */
    private function perUserAndIpLimits(Request $request, string $name, int $perUser, string $userWindow, int $perIpPerMinute, string $message, string $errorBag): array
    {
        $tooMany = fn (Request $request, array $headers) => $this->tooManyAttempts($request, $headers, $message, 'code', $errorBag);
        $userKey = "{$name}:user:".$request->user()?->getAuthIdentifier();

        return [
            ($userWindow === 'hour' ? Limit::perHour($perUser) : Limit::perMinute($perUser))->by($userKey)->response($tooMany),
            Limit::perMinute($perIpPerMinute)->by("{$name}:ip:".$request->ip())->response($tooMany),
        ];
    }

    /**
     * JSON 429 for the API; for the web, back to the form with the message.
     *
     * @param  array<string, string|int>  $headers
     */
    private function tooManyAttempts(Request $request, array $headers, string $message, string $errorKey = 'form', string $errorBag = 'default'): Response
    {
        $seconds = (int) $headers['Retry-After'];
        $fullMessage = $message.' Please try again in '.$seconds.' seconds.';

        if ($request->is('api/*') || $request->expectsJson()) {
            return response()->json(['message' => $fullMessage], 429, $headers);
        }

        // "throttle" lets a page show the wait as a live countdown (the sign-in page, resources/js/retry-countdown.js).
        return back()
            ->withInput($request->except('password', 'password_confirmation'))
            ->withErrors([$errorKey => $fullMessage], $errorBag)
            ->with('throttle', ['key' => $errorKey, 'message' => $message, 'seconds' => $seconds]);
    }
}
