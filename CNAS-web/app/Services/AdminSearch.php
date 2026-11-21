<?php

namespace App\Services;

use App\Enums\BuildingType;
use App\Http\Controllers\Admin\SettingsController;
use App\Models\Building;
use App\Models\Panorama;
use App\Models\Room;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Route;

/**
 * The admin top bar search ("Search buildings, rooms, people") and its results page. Every word typed must match
 * (in any order); each word may match any of a group's columns. LIKE wildcards in the input are treated as spaces,
 * as in User::scopeSearch, so "%" can't match everything. Runs on the server so people's details are never sent to
 * the browser in bulk.
 */
class AdminSearch
{
    /** Results per group in the top bar list. */
    public const SUGGESTION_LIMIT = 5;

    /** Results per group on the results page. */
    public const PAGE_LIMIT = 25;

    /** Shorter queries return nothing. */
    public const MIN_LENGTH = 2;

    /** Longer input is cut to this. */
    public const MAX_LENGTH = 100;

    /**
     * The groups that have at least one result, always in the same order.
     *
     * @return list<array{label: string, results: list<array{title: string, detail: string, url: string}>}>
     */
    public function search(string $query, int $limit = self::SUGGESTION_LIMIT): array
    {
        $words = $this->words($query);
        if ($words === [] || mb_strlen(implode(' ', $words)) < self::MIN_LENGTH) {
            return [];
        }

        $groups = [
            'Pages' => $this->pages($words, $limit),
            'Buildings' => $this->buildings($words, $limit),
            'Rooms' => $this->rooms($words, $limit),
            '360° photos' => $this->photos($words, $limit),
            'People' => $this->people($query, $words[0], $limit),
        ];

        return collect($groups)
            ->filter()
            ->map(fn (array $results, string $label) => ['label' => $label, 'results' => $results])
            ->values()
            ->all();
    }

    /**
     * At most 5 words; %, _ and \ split words, so they never reach LIKE as wildcards.
     *
     * @return list<string>
     */
    public function words(string $query): array
    {
        $query = mb_substr(trim($query), 0, self::MAX_LENGTH);

        return array_slice(preg_split('/[\s%_\\\\]+/u', $query, -1, PREG_SPLIT_NO_EMPTY) ?: [], 0, 5);
    }

    /**
     * Admin pages and settings tabs, found by their name or a few words people use for them ("semester", "password").
     *
     * @param  list<string>  $words
     * @return list<array{title: string, detail: string, url: string}>
     */
    private function pages(array $words, int $limit): array
    {
        $tab = fn (string $id) => SettingsController::tabUrl($id);
        $pages = [
            ['Overview', 'Dashboard: online users, sign-ins and sign-ups', 'admin.dashboard', null, 'dashboard home online activity'],
            ['Emergency alerts', 'Alert history', 'admin.alerts.index', null, 'alerts history fire earthquake flood typhoon drill'],
            ['Send an emergency alert', 'Emergency alerts', 'admin.alerts.create', null, 'new alert drill evacuate'],
            ['Map data', 'Campus map', 'admin.map.index', null, 'geojson import export download campus map'],
            ['Buildings', 'Campus map', 'admin.buildings.index', null, 'buildings names photos'],
            ['Rooms', 'Campus map', 'admin.rooms.index', null, 'rooms offices labs classrooms'],
            ['360° photos', 'Campus map', 'admin.panoramas.index', null, '360 photos panoramas street view'],
            ['Accounts', 'People', 'admin.accounts.index', null, 'accounts users students faculty staff create delete trusted'],
            ['Academic term', 'Site', 'admin.academic-terms.index', null, 'academic term semester school year registration cor'],
            ['Contact details', 'Settings', 'admin.settings', $tab('contact-details'), 'contact footer email phone facebook address'],
            ['Emergency contacts', 'Settings', 'admin.settings', $tab('emergency-contacts'), 'emergency numbers hotline clinic security guidance'],
            ['My account', 'Settings', 'admin.settings', $tab('my-account'), 'my account password email sign-in change'],
            ['System status', 'Settings', 'admin.settings', $tab('system-status'), 'system status https push keys vapid subscribers health'],
        ];

        $results = [];
        foreach ($pages as [$title, $detail, $route, $url, $keywords]) {
            $haystack = mb_strtolower("{$title} {$detail} {$keywords}");
            if (Route::has($route) && collect($words)->every(fn (string $word) => str_contains($haystack, mb_strtolower($word)))) {
                $results[] = ['title' => $title, 'detail' => $detail, 'url' => $url ?? route($route)];
            }
        }

        return array_slice($results, 0, $limit);
    }

    /**
     * Buildings with an edit page (the campus outline has none), by name, code or landmark name.
     *
     * @param  list<string>  $words
     * @return list<array{title: string, detail: string, url: string}>
     */
    private function buildings(array $words, int $limit): array
    {
        return $this->matching(Building::query()->where('type', '!=', BuildingType::Campus), $words, ['name', 'code', 'landmark_name'])
            ->limit($limit)
            ->get()
            ->map(fn (Building $building) => [
                'title' => $building->name,
                'detail' => "{$building->code} · {$building->type->label()}",
                'url' => route('admin.buildings.edit', $building),
            ])
            ->all();
    }

    /**
     * Rooms by name or code, or by the name of their building ("nee 201").
     *
     * @param  list<string>  $words
     * @return list<array{title: string, detail: string, url: string}>
     */
    private function rooms(array $words, int $limit): array
    {
        return $this->matching(Room::query()->with('building'), $words, ['name', 'code'], 'building')
            ->limit($limit)
            ->get()
            ->map(fn (Room $room) => [
                'title' => $room->name,
                'detail' => "{$room->code} · {$room->building?->name} · Floor {$room->floor}",
                'url' => route('admin.rooms.edit', $room),
            ])
            ->all();
    }

    /**
     * 360° photo points by name or code. They open in the photo map editor.
     *
     * @param  list<string>  $words
     * @return list<array{title: string, detail: string, url: string}>
     */
    private function photos(array $words, int $limit): array
    {
        return $this->matching(Panorama::query(), $words, ['name', 'code'])
            ->limit($limit)
            ->get()
            ->map(fn (Panorama $photo) => [
                'title' => $photo->name,
                'detail' => "{$photo->code} · Floor {$photo->floor} · ".($photo->is_visible ? 'Shown to users' : 'Hidden'),
                'url' => route('admin.panoramas.edit', $photo),
            ])
            ->all();
    }

    /**
     * People by name, school ID or email (User::scopeSearch). A person opens their page in Admin > Accounts.
     *
     * @return list<array{title: string, detail: string, url: string}>
     */
    private function people(string $query, string $firstWord, int $limit): array
    {
        return User::query()
            ->search($query)
            ->orderByRaw('CASE WHEN first_name LIKE ? OR last_name LIKE ? OR school_id LIKE ? THEN 0 ELSE 1 END', array_fill(0, 3, "{$firstWord}%"))
            ->orderBy('first_name')
            ->orderBy('last_name')
            ->limit($limit)
            ->get()
            ->map(fn (User $user) => [
                'title' => $user->full_name,
                'detail' => implode(' · ', array_filter([$user->role?->label(), $user->school_id, $user->email])),
                'url' => route('admin.accounts.show', $user),
            ])
            ->all();
    }

    /**
     * Every word must appear in one of $columns (or the name of the $relation). Names starting with the first word
     * come first, then by name.
     *
     * @template TModel of \Illuminate\Database\Eloquent\Model
     *
     * @param  Builder<TModel>  $query
     * @param  list<string>  $words
     * @param  list<string>  $columns
     * @return Builder<TModel>
     */
    private function matching(Builder $query, array $words, array $columns, ?string $relation = null): Builder
    {
        foreach ($words as $word) {
            $query->where(function (Builder $matches) use ($word, $columns, $relation) {
                foreach ($columns as $column) {
                    $matches->orWhere($column, 'like', "%{$word}%");
                }
                if ($relation) {
                    $matches->orWhereRelation($relation, 'name', 'like', "%{$word}%");
                }
            });
        }

        return $query
            ->orderByRaw('CASE WHEN name LIKE ? THEN 0 ELSE 1 END', ["{$words[0]}%"])
            ->orderBy('name');
    }
}
