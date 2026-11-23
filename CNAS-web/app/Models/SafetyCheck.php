<?php

namespace App\Models;

use App\Enums\SafetyStatus;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Prunable;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * A user's answer during an emergency: "I'm safe" or "I need help" (with an optional note). One per user and alert;
 * answering again replaces it. Admins only; deleted RETENTION_DAYS after the alert is resolved (Data Privacy Act).
 *
 * @property SafetyStatus $status
 * @property string|null $note Only with "I need help".
 * @property Carbon $responded_at
 */
#[Fillable(['alert_id', 'user_id', 'status', 'note', 'responded_at'])]
class SafetyCheck extends Model
{
    use Prunable;

    public const RETENTION_DAYS = 30;

    public $timestamps = false;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'status' => SafetyStatus::class,
            'responded_at' => 'datetime',
        ];
    }

    /**
     * Mark the user safe for this alert (it replaces an earlier "I need help"). Answering again only updates the time.
     */
    public static function markSafe(Alert $alert, User $user): self
    {
        return static::updateOrCreate(
            ['alert_id' => $alert->id, 'user_id' => $user->id],
            ['status' => SafetyStatus::Safe, 'note' => null, 'responded_at' => now()],
        );
    }

    /**
     * "I need help", with what the person typed (where they are, what is wrong). It replaces an earlier "I'm safe".
     */
    public static function markNeedHelp(Alert $alert, User $user, ?string $note): self
    {
        return static::updateOrCreate(
            ['alert_id' => $alert->id, 'user_id' => $user->id],
            ['status' => SafetyStatus::NeedHelp, 'note' => filled($note) ? trim($note) : null, 'responded_at' => now()],
        );
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
}
