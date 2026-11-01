<?php

namespace App\Models;

use App\Enums\Semester;
use Database\Factories\AcademicTermFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
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
