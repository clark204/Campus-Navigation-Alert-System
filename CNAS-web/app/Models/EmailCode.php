<?php

namespace App\Models;

use App\Enums\EmailCodePurpose;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Prunable;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A 6-digit code emailed to a user (only its hash is kept). Created and checked by App\Services\EmailCodes only, so
 * nothing is mass assignable.
 */
class EmailCode extends Model
{
    use Prunable;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'purpose' => EmailCodePurpose::class,
            'attempts' => 'integer',
            'expires_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function isExpired(): bool
    {
        return $this->expires_at->lte(now());
    }

    /**
     * Expired codes, removed by the scheduled `model:prune` (see routes/console.php).
     *
     * @return Builder<self>
     */
    public function prunable(): Builder
    {
        return static::where('expires_at', '<=', now());
    }
}
