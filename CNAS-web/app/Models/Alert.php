<?php

namespace App\Models;

use App\Enums\AlertLogAction;
use App\Enums\AlertResolution;
use App\Enums\AlertSeverity;
use App\Enums\AlertStatus;
use App\Enums\AlertType;
use App\Jobs\FanOutExpoAlertPush;
use App\Jobs\SendAlertPush;
use App\Services\CampusMap;
use Database\Factories\AlertFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Arr;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * An emergency alert. Admins send it (active), may edit its wording, then resolve it as "All clear" or "False alarm".
 * Only one alert is active at a time. Every page shows the current one (see current()), and every change that matters
 * to people (sent, resolved) is pushed to subscribed browsers (SendAlertPush) and phones (FanOutExpoAlertPush).
 *
 * Location: an optional incident pin (latitude, longitude) with a danger radius, and the affected buildings
 * (building_codes: codes from the buildings table). Whole-campus alerts have neither.
 *
 * @property AlertType $type
 * @property AlertSeverity $severity
 * @property AlertStatus $status
 * @property ?AlertResolution $resolution
 * @property bool $is_drill
 * @property ?float $latitude
 * @property ?float $longitude
 * @property ?int $danger_radius_m
 * @property list<string> $building_codes
 * @property Carbon $sent_at
 * @property ?Carbon $resolved_at
 */
#[Fillable(['type', 'severity', 'title', 'message', 'instructions', 'is_drill', 'latitude', 'longitude', 'danger_radius_m', 'building_codes'])]
class Alert extends Model
{
    /** @use HasFactory<AlertFactory> */
    use HasFactory;

    /** After an alert is resolved, the green "All clear" / "False alarm" banner shows this long, then hides itself. */
    public const ALL_CLEAR_MINUTES = 15;

    /** Danger zone sizes the admin can pick, in meters. */
    public const DANGER_RADII = [30, 50, 100];

    public const DEFAULT_DANGER_RADIUS = 50;

    private const CURRENT_CACHE_KEY = 'alerts.current';

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'type' => AlertType::class,
            'severity' => AlertSeverity::class,
            'status' => AlertStatus::class,
            'resolution' => AlertResolution::class,
            'is_drill' => 'boolean',
            'latitude' => 'float',
            'longitude' => 'float',
            'danger_radius_m' => 'integer',
            'building_codes' => 'array',
            'sent_at' => 'datetime',
            'resolved_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::saved(fn () => Cache::forget(self::CURRENT_CACHE_KEY));
        static::deleted(fn () => Cache::forget(self::CURRENT_CACHE_KEY));
    }

    /**
     * What the banner shows: the active alert, or the last one resolved in the past ALL_CLEAR_MINUTES. Every page
     * and the app poll this, so it is cached until an alert changes.
     */
    public static function current(): ?self
    {
        // Raw attributes, not the model: the cache refuses to unserialize objects (cache.serializable_classes).
        $attributes = Cache::rememberForever(self::CURRENT_CACHE_KEY, fn () => (
            static::active()->latest('sent_at')->first()
            ?? static::where('status', AlertStatus::Resolved)->latest('resolved_at')->first()
        )?->getAttributes() ?? []);

        $alert = $attributes === [] ? null : static::query()->hydrate([$attributes])->first();

        return $alert?->isActive() || $alert?->resolved_at?->gt(now()->subMinutes(self::ALL_CLEAR_MINUTES)) ? $alert : null;
    }

    /**
     * Send a new alert and push it to every subscribed device. Fails if one is already active: edit or resolve that
     * one instead.
     *
     * @param  array{type: AlertType|string, severity: AlertSeverity|string, title: string, message: string, instructions?: ?string, is_drill?: bool, latitude?: ?float, longitude?: ?float, danger_radius_m?: ?int, building_codes?: list<string>}  $attributes
     *
     * @throws ValidationException
     */
    public static function send(array $attributes, User $by): self
    {
        $alert = Cache::lock('alerts.send', 10)->block(5, fn () => DB::transaction(function () use ($attributes, $by) {
            if (static::active()->exists()) {
                throw ValidationException::withMessages(['form' => 'An alert is already active. Edit or resolve it first.']);
            }

            $alert = new static($attributes);
            $alert->forceFill(['status' => AlertStatus::Active, 'created_by' => $by->id, 'sent_at' => now()])->save();
            $alert->log(AlertLogAction::Sent, $by);

            return $alert;
        }));

        $alert->pushToDevices();

        return $alert;
    }

    /**
     * Change how serious an active alert is. It is logged, and every subscribed device gets a new notification (the same
     * tag on the phone replaces the old one). Nothing changes when the level stays the same.
     */
    public function changeSeverity(AlertSeverity $to, User $by): void
    {
        if ($this->severity === $to) {
            return;
        }

        $from = $this->severity;
        DB::transaction(function () use ($to, $by, $from) {
            $this->forceFill(['severity' => $to])->save();
            $this->log(AlertLogAction::SeverityChanged, $by, "{$from->label()} → {$to->label()}");
        });

        $this->pushToDevices();
    }

    /**
     * Change the wording of an active alert. The banner picks it up on its next poll; no new push.
     *
     * @param  array{title: string, message: string, instructions?: ?string}  $attributes
     */
    public function revise(array $attributes, User $by): void
    {
        DB::transaction(function () use ($attributes, $by) {
            $this->fill(Arr::only($attributes, ['title', 'message', 'instructions']))->save();
            $this->log(AlertLogAction::Edited, $by);
        });
    }

    /**
     * End the emergency (all clear) or call it off (false alarm). The banner turns green for ALL_CLEAR_MINUTES and
     * subscribed devices get a push.
     */
    public function resolve(string $message, User $by, AlertResolution $resolution = AlertResolution::AllClear): void
    {
        DB::transaction(function () use ($message, $by, $resolution) {
            $this->forceFill([
                'status' => AlertStatus::Resolved,
                'resolution' => $resolution,
                'resolved_at' => now(),
                'resolved_by' => $by->id,
                'resolved_message' => $message,
            ])->save();
            $this->log(AlertLogAction::Resolved, $by);
        });

        $this->pushToDevices($resolution);
    }

    /**
     * Push to every browser that turned alerts on (SendAlertPush) and every phone with the app (FanOutExpoAlertPush).
     */
    private function pushToDevices(?AlertResolution $resolution = null): void
    {
        SendAlertPush::dispatch($this, $resolution);
        FanOutExpoAlertPush::dispatch($this, $resolution);
    }

    private function log(AlertLogAction $action, User $by, ?string $detail = null): void
    {
        $this->logs()->create(['user_id' => $by->id, 'action' => $action, 'detail' => $detail, 'created_at' => now()]);
    }

    public function isActive(): bool
    {
        return $this->status === AlertStatus::Active;
    }

    /**
     * When the green banner of a resolved alert stops showing (null while it is active). The API sends it so pages
     * can hide the banner on time even between polls.
     */
    public function bannerExpiresAt(): ?Carbon
    {
        return $this->resolved_at?->copy()->addMinutes(self::ALL_CLEAR_MINUTES);
    }

    public function hasPin(): bool
    {
        return $this->latitude !== null && $this->longitude !== null;
    }

    /**
     * Whether the alert says where it is: a pin, or affected buildings. A whole-campus alert has neither.
     */
    public function hasLocation(): bool
    {
        return $this->hasPin() || filled($this->building_codes);
    }

    /**
     * Only an emergency takes over the screen and asks "I'm safe".
     */
    public function isEmergency(): bool
    {
        return $this->severity->isEmergency();
    }

    /**
     * Where it is, for people: "NEE Building", "NEE Building and Library Building", "NEE Building and 2 more
     * buildings", "Marked on the map" (a pin outside any building) or "Whole campus".
     */
    public function locationLabel(): string
    {
        $map = app(CampusMap::class);
        $names = collect($this->building_codes ?? [])->map(fn (string $code) => $map->buildingName($code))->filter()->values();

        return match (true) {
            $names->count() === 1 => $names[0],
            $names->count() === 2 => "{$names[0]} and {$names[1]}",
            $names->count() > 2 => "{$names[0]} and ".($names->count() - 1).' more buildings',
            $this->hasPin() => 'Marked on the map',
            default => 'Whole campus',
        };
    }

    /**
     * Changes whenever the banner's content does, so clients only redraw (and screen readers only re-announce) then.
     */
    public function version(): string
    {
        return "{$this->id}-{$this->status->value}-{$this->updated_at?->getTimestamp()}";
    }

    /**
     * @param  Builder<self>  $query
     * @return Builder<self>
     */
    public function scopeActive(Builder $query): Builder
    {
        return $query->where('status', AlertStatus::Active);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function resolver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'resolved_by');
    }

    /**
     * @return HasMany<AlertLog, $this>
     */
    public function logs(): HasMany
    {
        return $this->hasMany(AlertLog::class);
    }

    /**
     * @return HasMany<SafetyCheck, $this>
     */
    public function safetyChecks(): HasMany
    {
        return $this->hasMany(SafetyCheck::class);
    }
}
