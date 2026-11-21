<?php

namespace App\Models;

use App\Services\ContactDetails;
use Database\Factories\EmergencyContactFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * An emergency number (campus security, clinic…) on the portal home and /api/emergency-contacts. Only visible
 * contacts with a number are listed, in position order. Showing a contact needs a number (the admin forms check it).
 *
 * @property string $name
 * @property string|null $phone Written for people (e.g. "+63 912-345-6789"); see telHref().
 * @property string|null $note e.g. "24 hours".
 * @property int $position
 * @property bool $is_visible
 */
#[Fillable(['name', 'phone', 'note', 'is_visible'])]
class EmergencyContact extends Model
{
    /** @use HasFactory<EmergencyContactFactory> */
    use HasFactory;

    public const LISTED_CACHE_KEY = 'emergency-contacts.listed';

    protected static function booted(): void
    {
        static::creating(function (EmergencyContact $contact): void {
            $contact->position ??= (int) static::max('position') + 1;
        });
        static::saved(fn () => Cache::forget(self::LISTED_CACHE_KEY));
        static::deleted(fn () => Cache::forget(self::LISTED_CACHE_KEY));
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'position' => 'integer',
            'is_visible' => 'boolean',
        ];
    }

    /**
     * What students see: visible contacts with a number, in order. Cached until a contact changes.
     *
     * @return Collection<int, self>
     */
    public static function listed(): Collection
    {
        // Raw attributes, not models: the cache refuses to unserialize objects (cache.serializable_classes).
        $rows = Cache::rememberForever(self::LISTED_CACHE_KEY, fn () => static::query()
            ->where('is_visible', true)
            ->whereNotNull('phone')
            ->orderBy('position')
            ->orderBy('id')
            ->get()
            ->map(fn (self $contact) => $contact->getAttributes())
            ->all());

        return static::query()->hydrate($rows);
    }

    /**
     * Every contact in order, for the admin list.
     *
     * @return Collection<int, self>
     */
    public static function ordered(): Collection
    {
        return static::query()->orderBy('position')->orderBy('id')->get();
    }

    /**
     * Swap this contact with its neighbour ('up' or 'down'). Nothing happens at either end of the list.
     */
    public function move(string $direction): void
    {
        DB::transaction(function () use ($direction): void {
            $contacts = static::ordered()->all();
            $index = array_search($this->id, array_column($contacts, 'id'), true);
            $target = $direction === 'up' ? $index - 1 : $index + 1;

            if ($index === false || ! isset($contacts[$target])) {
                return;
            }

            [$contacts[$index], $contacts[$target]] = [$contacts[$target], $contacts[$index]];
            foreach ($contacts as $position => $contact) {
                if ($contact->position !== $position) {
                    // position isn't fillable: only this method orders the list.
                    $contact->forceFill(['position' => $position])->save();
                }
            }
        });
    }

    public function telHref(): string
    {
        return ContactDetails::telHref((string) $this->phone);
    }
}
