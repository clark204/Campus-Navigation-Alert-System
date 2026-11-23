<?php

namespace App\Models;

use App\Enums\Semester;
use Database\Factories\AcademicTermFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * An academic term (AY + semester). The current one decides which CORs can register.
 * The database is the only source of truth; is_current is changed only through makeCurrent().
 *
 * @property string $academic_year
 * @property Semester $semester
 * @property bool $is_current
 */
#[Fillable(['academic_year', 'semester'])]
class AcademicTerm extends Model
{
    /** @use HasFactory<AcademicTermFactory> */
    use HasFactory;

    private const CURRENT_CACHE_KEY = 'academic_terms.current';

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'semester' => Semester::class,
            'is_current' => 'boolean',
            'set_current_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        // Any change to a term may change which one is current.
        static::saved(fn () => Cache::forget(self::CURRENT_CACHE_KEY));
        static::deleted(fn () => Cache::forget(self::CURRENT_CACHE_KEY));
    }

    /**
     * The current term, or null when registration is closed. Cached until a term changes.
     */
    public static function current(): ?self
    {
        // Raw attributes, not the model: the cache refuses to unserialize objects (cache.serializable_classes).
        $attributes = Cache::rememberForever(
            self::CURRENT_CACHE_KEY,
            fn () => static::where('is_current', true)->first()?->getAttributes() ?? [],
        );

        return $attributes === [] ? null : static::query()->hydrate([$attributes])->first();
    }

    /** Remind the admin to check the current term once it has been current this long. */
    public const REMINDER_MONTHS = 5;

    /**
     * The current term has been current for REMINDER_MONTHS: the dashboard (web and app) asks whether it is still
     * right.
     */
    public function mayBeOutdated(): bool
    {
        return $this->set_current_at?->lt(now()->subMonths(self::REMINDER_MONTHS)) ?? false;
    }

    /**
     * The dashboard's warning when the admin should act (web banner and the app's card), or null: no current term
     * ("closed": registration is closed) or one set REMINDER_MONTHS ago ("outdated").
     *
     * @return array{kind: 'closed'|'outdated', message: string}|null
     */
    public static function warning(): ?array
    {
        $current = static::current();

        return match (true) {
            $current === null => ['kind' => 'closed', 'message' => 'Registration is closed: no academic term is set as current.'],
            $current->mayBeOutdated() => [
                'kind' => 'outdated',
                'message' => "The current term ({$current->label()}) was set {$current->set_current_at->diffForHumans()}. Is it still right? Students can only register with a COR for this term.",
            ],
            default => null,
        };
    }

    /**
     * Every term, newest first (Settings → Academic term, web and app).
     *
     * @return Collection<int, self>
     */
    public static function newestFirst(): Collection
    {
        return static::orderByDesc('academic_year')->orderByDesc('semester')->get();
    }

    /**
     * Make this the only current term.
     */
    public function makeCurrent(): void
    {
        DB::transaction(function () {
            static::where('is_current', true)->whereKeyNot($this->getKey())->update(['is_current' => false]);

            $this->forceFill(['is_current' => true, 'set_current_at' => now()])->save();
        });

        Cache::forget(self::CURRENT_CACHE_KEY);
    }

    /**
     * e.g. "AY 2026-2027, 1st Semester".
     */
    public function label(): string
    {
        return "AY {$this->academic_year}, {$this->semester->label()}";
    }
}
