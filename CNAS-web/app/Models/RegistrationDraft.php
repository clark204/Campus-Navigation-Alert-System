<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Prunable;

/**
 * The details read from a COR that passed every check, kept for 30 minutes while the
 * student finishes signing up. Created by App\Services\RegistrationService only, so
 * nothing is mass assignable.
 *
 * @property array{student_id: string, full_name: string, first_name: ?string, middle_name: ?string, last_name: ?string, college_id: string} $data
 */
class RegistrationDraft extends Model
{
    use Prunable;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'data' => 'array',
            'expires_at' => 'datetime',
        ];
    }

    public static function hashToken(string $token): string
    {
        return hash('sha256', $token);
    }

    /**
     * @param  Builder<self>  $query
     * @return Builder<self>
     */
    public function scopeActive(Builder $query): Builder
    {
        return $query->where('expires_at', '>', now());
    }

    /**
     * Expired drafts, removed by the scheduled `model:prune` (see routes/console.php).
     *
     * @return Builder<self>
     */
    public function prunable(): Builder
    {
        return static::where('expires_at', '<=', now());
    }
}
