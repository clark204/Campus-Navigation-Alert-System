<?php

namespace App\Models;

use App\Enums\AlertLogAction;
use App\Enums\HelpStatus;
use App\Enums\SafetyStatus;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Prunable;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * A user's answer during an emergency: "I'm safe" or "I need help" (with an optional note). One per user and alert;
 * answering again replaces it. Admins only; deleted RETENTION_DAYS after the alert is resolved (Data Privacy Act).
 *
 * "I need help" is also a help request (docs/alerts.md): the person's latest position (updated about every 15 s while
 * their page or app is open and the request is open), then an admin's "I'm responding" and "Mark helped".
 *
 * @property SafetyStatus $status
 * @property string|null $note Only with "I need help".
 * @property Carbon $responded_at
 * @property float|null $latitude
 * @property float|null $longitude
 * @property int|null $accuracy_m
 * @property Carbon|null $located_at
 * @property int|null $responder_id
 * @property Carbon|null $responding_at
 * @property int|null $helped_by
 * @property Carbon|null $helped_at
 */
#[Fillable(['alert_id', 'user_id', 'status', 'note', 'responded_at', 'latitude', 'longitude', 'accuracy_m', 'located_at', 'responder_id', 'responding_at', 'helped_by', 'helped_at'])]
class SafetyCheck extends Model
{
    use Prunable;

    public const RETENTION_DAYS = 30;

    /** A position older than this is shown as stale ("Last seen 3 min ago"). */
    public const LOCATION_FRESH_SECONDS = 60;

    /** The help request's fields, cleared when the person answers again. */
    private const HELP_RESET = [
        'latitude' => null,
        'longitude' => null,
        'accuracy_m' => null,
        'located_at' => null,
        'responder_id' => null,
        'responding_at' => null,
        'helped_by' => null,
        'helped_at' => null,
    ];

    public $timestamps = false;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'status' => SafetyStatus::class,
            'responded_at' => 'datetime',
            'latitude' => 'float',
            'longitude' => 'float',
            'accuracy_m' => 'integer',
            'located_at' => 'datetime',
            'responding_at' => 'datetime',
            'helped_at' => 'datetime',
        ];
    }

    /**
     * Mark the user safe for this alert. It replaces an earlier "I need help", which closes the help request and
     * forgets where they were. Answering again only updates the time.
     */
    public static function markSafe(Alert $alert, User $user): self
    {
        return static::updateOrCreate(
            ['alert_id' => $alert->id, 'user_id' => $user->id],
            ['status' => SafetyStatus::Safe, 'note' => null, 'responded_at' => now(), ...self::HELP_RESET],
        );
    }

    /**
     * "I need help", with what the person typed (where they are, what is wrong) and, when they shared it, where their
     * phone or browser says they are. It replaces an earlier "I'm safe"; asking again opens a new request.
     *
     * @param  array{latitude: float, longitude: float, accuracy: float|null}|null  $location
     */
    public static function markNeedHelp(Alert $alert, User $user, ?string $note, ?array $location = null): self
    {
        return static::updateOrCreate(
            ['alert_id' => $alert->id, 'user_id' => $user->id],
            [
                'status' => SafetyStatus::NeedHelp,
                'note' => filled($note) ? trim($note) : null,
                'responded_at' => now(),
                ...self::HELP_RESET,
                ...self::locationFields($location),
            ],
        );
    }

    /**
     * The person's latest position while their request is open (HelpPingController). Only the latest is kept.
     *
     * @param  array{latitude: float, longitude: float, accuracy: float|null}  $location
     */
    public function updateLocation(array $location): void
    {
        $this->update(self::locationFields($location));
    }

    /**
     * An admin taps "I'm responding": the person sees "<name> is on the way". Another admin can take it over.
     */
    public function respond(User $admin): void
    {
        DB::transaction(function () use ($admin) {
            $this->update(['responder_id' => $admin->id, 'responding_at' => now()]);
            $this->logForAlert(AlertLogAction::HelpResponding, $admin);
        });
    }

    /**
     * An admin marks the person helped: the request closes and their page or app stops sharing where they are.
     */
    public function markHelped(User $admin): void
    {
        DB::transaction(function () use ($admin) {
            $this->update(['helped_by' => $admin->id, 'helped_at' => now()]);
            $this->logForAlert(AlertLogAction::HelpResolved, $admin);
        });
    }

    /**
     * Where the help request is; null for "I'm safe".
     */
    public function helpStatus(): ?HelpStatus
    {
        return match (true) {
            $this->status !== SafetyStatus::NeedHelp => null,
            $this->helped_at !== null => HelpStatus::Helped,
            $this->responder_id !== null => HelpStatus::Responding,
            default => HelpStatus::New,
        };
    }

    public function hasLocation(): bool
    {
        return $this->latitude !== null && $this->longitude !== null;
    }

    /**
     * "I need help" answers nobody has marked helped yet.
     *
     * @param  Builder<self>  $query
     * @return Builder<self>
     */
    public function scopeOpenHelp(Builder $query): Builder
    {
        return $query->where('status', SafetyStatus::NeedHelp)->whereNull('helped_at');
    }

    /**
     * @return Builder<self>
     */
    public function prunable(): Builder
    {
        return static::whereHas('alert', fn (Builder $alerts) => $alerts->where('resolved_at', '<', now()->subDays(self::RETENTION_DAYS)));
    }

    /**
     * @return BelongsTo<Alert, $this>
     */
    public function alert(): BelongsTo
    {
        return $this->belongsTo(Alert::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * The admin who tapped "I'm responding".
     *
     * @return BelongsTo<User, $this>
     */
    public function responder(): BelongsTo
    {
        return $this->belongsTo(User::class, 'responder_id');
    }

    /**
     * The admin who marked the person helped.
     *
     * @return BelongsTo<User, $this>
     */
    public function helper(): BelongsTo
    {
        return $this->belongsTo(User::class, 'helped_by');
    }

    /**
     * @param  array{latitude: float, longitude: float, accuracy: float|null}|null  $location
     * @return array<string, mixed>
     */
    private static function locationFields(?array $location): array
    {
        if ($location === null) {
            return [];
        }

        return [
            'latitude' => round($location['latitude'], 7),
            'longitude' => round($location['longitude'], 7),
            'accuracy_m' => $location['accuracy'] === null ? null : min(65535, max(0, (int) round($location['accuracy']))),
            'located_at' => now(),
        ];
    }

    private function logForAlert(AlertLogAction $action, User $admin): void
    {
        $this->loadMissing(['user', 'alert']);
        $this->alert->logs()->create(['user_id' => $admin->id, 'action' => $action, 'detail' => $this->user?->full_name, 'created_at' => now()]);
    }
}
