<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Prunable;

/**
 * The details read from a COR that passed every check (identity and class meetings), kept
 * for 30 minutes while the student finishes signing up. Created by App\Services\RegistrationService only, so
 * nothing is mass assignable.
 *
 * @property array{student_id: string, full_name: string, first_name: ?string, middle_name: ?string, last_name: ?string, program_code: string, academic_term_id: int, meetings: list<array{subject: string, section: string, day: string, starts_at: string, ends_at: string, room_text: string}>} $data
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

    /**
     * The COR's program with its college, for the confirm step. Null if the program has since been removed.
     */
    public function program(): ?Program
    {
        return Program::with('college')->find($this->data['program_code'] ?? null);
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
