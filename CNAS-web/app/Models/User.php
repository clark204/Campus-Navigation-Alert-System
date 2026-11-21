<?php

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use App\Enums\UserRole;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\Relations\HasOneThrough;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Str;
use Laravel\Sanctum\HasApiTokens;
use NotificationChannels\WebPush\HasPushSubscriptions;

// 'role', 'verified_at', 'email_verified_at', 'alert_trusted_at', 'last_seen_at' and 'must_change_password' are
// deliberately not fillable: registration can't self-assign admin or alert sending, or skip the emailed code.
#[Fillable(['school_id', 'first_name', 'middle_name', 'last_name', 'email', 'program_code', 'password'])]
#[Hidden(['password', 'remember_token'])]
class User extends Authenticatable
{
    /** @use HasFactory<UserFactory> */
    use HasApiTokens, HasFactory, HasPushSubscriptions, Notifiable;

    /** "Online" means a signed-in request within this many minutes. */
    public const ONLINE_MINUTES = 5;

    /** last_seen_at is written at most once per this many seconds, not on every request. */
    public const SEEN_INTERVAL_SECONDS = 60;

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'verified_at' => 'datetime',
            'alert_trusted_at' => 'datetime',
            'last_seen_at' => 'datetime',
            'password' => 'hashed',
            'must_change_password' => 'boolean',
            'role' => UserRole::class,
        ];
    }

    /**
     * @return BelongsTo<Program, $this>
     */
    public function program(): BelongsTo
    {
        return $this->belongsTo(Program::class, 'program_code', 'code');
    }

    /**
     * The college of the user's program. Null without a program.
     *
     * @return HasOneThrough<College, Program, $this>
     */
    public function college(): HasOneThrough
    {
        return $this->hasOneThrough(College::class, Program::class, 'code', 'id', 'program_code', 'college_id');
    }

    /**
     * @return HasMany<LoginActivity, $this>
     */
    public function loginActivities(): HasMany
    {
        return $this->hasMany(LoginActivity::class);
    }

    /**
     * A student's class meetings in every term. Read them through App\Services\ClassSchedules (current term only).
     *
     * @return HasMany<ClassSchedule, $this>
     */
    public function classSchedules(): HasMany
    {
        return $this->hasMany(ClassSchedule::class);
    }

    /**
     * Rooms the user saved from the directions card, newest first.
     *
     * @return BelongsToMany<Room, $this>
     */
    public function savedRooms(): BelongsToMany
    {
        return $this->belongsToMany(Room::class, 'saved_places')->withTimestamps()->orderByPivot('created_at', 'desc')->orderByPivot('id', 'desc');
    }

    /**
     * Rooms the user last started directions to, newest first (see App\Services\RecentPlaces).
     *
     * @return BelongsToMany<Room, $this>
     */
    public function recentRooms(): BelongsToMany
    {
        return $this->belongsToMany(Room::class, 'recent_places')->withPivot('used_at')->orderByPivot('used_at', 'desc')->orderByPivot('id', 'desc');
    }

    /**
     * The user's "I'm safe" answers during emergencies.
     *
     * @return HasMany<SafetyCheck, $this>
     */
    public function safetyChecks(): HasMany
    {
        return $this->hasMany(SafetyCheck::class);
    }

    /**
     * Concerns the user sent from Help & contact.
     *
     * @return HasMany<Concern, $this>
     */
    public function concerns(): HasMany
    {
        return $this->hasMany(Concern::class);
    }

    /**
     * The newest sign-in, for the device shown next to an online user.
     *
     * @return HasOne<LoginActivity, $this>
     */
    public function latestLoginActivity(): HasOne
    {
        return $this->hasOne(LoginActivity::class)->latestOfMany('logged_in_at');
    }

    /**
     * @param  Builder<self>  $query
     * @return Builder<self>
     */
    public function scopeOnline(Builder $query): Builder
    {
        return $query->where('last_seen_at', '>=', now()->subMinutes(self::ONLINE_MINUTES));
    }

    /**
     * Every word must appear in the first name, last name, school ID or email ("juan dela" finds Juan Dela Cruz).
     * LIKE wildcards in the input are treated as spaces, so "%" can't match everything.
     *
     * @param  Builder<self>  $query
     * @return Builder<self>
     */
    public function scopeSearch(Builder $query, string $search): Builder
    {
        $words = array_slice(preg_split('/[\s%_\\\\]+/', $search, -1, PREG_SPLIT_NO_EMPTY), 0, 5);

        foreach ($words as $word) {
            $query->where(fn (Builder $matches) => $matches
                ->where('first_name', 'like', "%{$word}%")
                ->orWhere('last_name', 'like', "%{$word}%")
                ->orWhere('school_id', 'like', "%{$word}%")
                ->orWhere('email', 'like', "%{$word}%"));
        }

        return $query;
    }

    public function isOnline(): bool
    {
        return $this->last_seen_at?->gte(now()->subMinutes(self::ONLINE_MINUTES)) ?? false;
    }

    /**
     * Note a signed-in request, skipped when last_seen_at was written less than SEEN_INTERVAL_SECONDS ago.
     * Doesn't touch updated_at: being seen isn't an edit to the account.
     */
    public function markSeen(): void
    {
        if ($this->last_seen_at?->gt(now()->subSeconds(self::SEEN_INTERVAL_SECONDS))) {
            return;
        }

        static::withoutTimestamps(fn () => $this->forceFill(['last_seen_at' => now()])->saveQuietly());
    }

    /**
     * First and last name, as shown in the top bar.
     *
     * @return Attribute<string, never>
     */
    protected function fullName(): Attribute
    {
        return Attribute::get(fn (): string => "{$this->first_name} {$this->last_name}");
    }

    /**
     * First letters of the first and last name, for the avatar.
     *
     * @return Attribute<string, never>
     */
    protected function initials(): Attribute
    {
        return Attribute::get(fn (): string => Str::upper(Str::substr($this->first_name, 0, 1).Str::substr($this->last_name, 0, 1)));
    }

    public function isAdmin(): bool
    {
        return $this->role === UserRole::Admin;
    }

    /**
     * The email address was confirmed with an emailed code (email_verified_at). Not the same as verified_at, which
     * means the account was COR-verified at registration.
     */
    public function hasVerifiedEmail(): bool
    {
        return $this->email_verified_at !== null;
    }

    /**
     * Portal pages send this user to "Verify your email" first. The admin never has to, so a mail problem can't
     * lock the admin out.
     */
    public function needsEmailVerification(): bool
    {
        return ! $this->isAdmin() && ! $this->hasVerifiedEmail();
    }

    /**
     * Where this user goes after signing in (or when opening a guest page): "Choose a new password" while they have
     * a temporary password from the admin, then "Verify your email" while it isn't verified, then their home.
     */
    public function nextRoute(): string
    {
        return match (true) {
            $this->must_change_password => 'password.change',
            $this->needsEmailVerification() => 'email.verify',
            default => $this->role->homeRoute(),
        };
    }

    public function markEmailAsVerified(): void
    {
        $this->forceFill(['email_verified_at' => now()])->save();
    }

    /**
     * Only faculty and staff can be trusted to send emergency alerts (never students; admins always can).
     */
    public function canBeTrustedForAlerts(): bool
    {
        return in_array($this->role, [UserRole::Faculty, UserRole::Staff], true);
    }

    /**
     * Marked trusted by the admin, and still in a role that can be (a role change takes the trust away).
     */
    public function isTrustedForAlerts(): bool
    {
        return $this->alert_trusted_at !== null && $this->canBeTrustedForAlerts();
    }

    /**
     * May send an emergency alert and end the active one: the admin, or a trusted faculty or staff member.
     */
    public function canSendAlerts(): bool
    {
        return $this->isAdmin() || $this->isTrustedForAlerts();
    }

    /**
     * Turns alert sending on or off for this user (Admin > Accounts). Only faculty and staff can be trusted.
     */
    public function trustForAlerts(bool $trusted): void
    {
        $this->forceFill(['alert_trusted_at' => $trusted && $this->canBeTrustedForAlerts() ? ($this->alert_trusted_at ?? now()) : null])->save();
    }

    /**
     * The user's settings page with one tab open: the admin's or their portal's.
     */
    public function settingsUrl(?string $tab = null): string
    {
        $route = $this->isAdmin() ? 'admin.settings' : $this->role->portal()->value.'.settings';

        return route($route, array_filter(['tab' => $tab]));
    }
}
