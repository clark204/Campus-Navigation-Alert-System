<?php

namespace App\Models;

use App\Enums\ConcernTopic;
use Database\Factories\ConcernFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * A concern, suggestion or feedback sent from Help & contact (or the site footer by a guest). The admin reads them on
 * Admin > Concerns, replies there (ConcernReply: emailed, and shown in the sender's portal) and marks them resolved.
 *
 * @property int|null $user_id Null for a guest.
 * @property string $email Where the admin replies.
 * @property ConcernTopic $topic
 * @property string $message
 * @property Carbon|null $resolved_at
 */
#[Fillable(['email', 'topic', 'message'])]
class Concern extends Model
{
    /** @use HasFactory<ConcernFactory> */
    use HasFactory;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'topic' => ConcernTopic::class,
            'resolved_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * @return HasMany<ConcernReply, $this>
     */
    public function replies(): HasMany
    {
        return $this->hasMany(ConcernReply::class)->oldest()->oldest('id');
    }

    /**
     * @param  Builder<self>  $query
     * @return Builder<self>
     */
    public function scopeOpen(Builder $query): Builder
    {
        return $query->whereNull('resolved_at');
    }

    /**
     * @param  Builder<self>  $query
     * @return Builder<self>
     */
    public function scopeResolved(Builder $query): Builder
    {
        return $query->whereNotNull('resolved_at');
    }

    public function isResolved(): bool
    {
        return $this->resolved_at !== null;
    }
}
