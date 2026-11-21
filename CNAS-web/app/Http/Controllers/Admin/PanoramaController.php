<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\SavePanoramasRequest;
use App\Http\Requests\Admin\StorePanoramaRequest;
use App\Http\Requests\Admin\UpdatePanoramaRequest;
use App\Http\Requests\Admin\UpdatePanoramaVisibilityRequest;
use App\Models\Panorama;
use App\Services\CampusMap;
use App\Services\PanoramaImages;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Number;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

/**
 * Admin "360° photos": the photo points users can open on the maps. Photos come from php artisan panoramas:import or
 * an upload here; their position (EXIF GPS can be off under trees) and north are fixed on the edit page. A photo is
 * only shown to users once north is set and it is made visible.
 */
class PanoramaController extends Controller
{
    /** A photo further than this from every walkway on its floor gets a "Check position" badge. */
    public const CHECK_POSITION_METERS = 10;

    /** Two admins uploading at the same moment can pick the same next code; try again this many times. */
    private const CODE_ATTEMPTS = 3;

    /** Photos per page on the list. */
    public const PAGE_SIZE = 5;

    /** The status filter's choices: a key → label. */
    public const STATUSES = [
        'all' => 'All photos',
        'shown' => 'Shown to users',
        'hidden' => 'Hidden',
        'no_north' => 'North not set',
        'check' => 'Check position',
    ];

    public function index(Request $request, CampusMap $campusMap): View
    {
        $search = trim((string) $request->query('q', ''));
        $requested = (string) $request->query('status', 'all');
        $status = array_key_exists($requested, self::STATUSES) ? $requested : 'all';
        $offPath = $this->distancesFromWalkways($campusMap);

        $rows = $this->inCodeOrder(Panorama::query()->get())
            ->map(fn (Panorama $panorama) => [
                'panorama' => $panorama,
                'meters' => $offPath[$panorama->code],
                'checkPosition' => $this->needsCheck($offPath[$panorama->code]),
            ])
            ->filter(fn (array $row) => $search === '' || str_contains(mb_strtolower($row['panorama']->name), mb_strtolower($search))
                || str_contains(mb_strtolower($row['panorama']->code), mb_strtolower($search)))
            ->filter(fn (array $row) => match ($status) {
                'shown' => $row['panorama']->is_visible,
                'hidden' => ! $row['panorama']->is_visible,
                'no_north' => ! $row['panorama']->hasNorth(),
                'check' => $row['checkPosition'],
                default => true,
            })
            ->values();

        $page = max(1, (int) $request->query('page', 1));
        $paginator = new LengthAwarePaginator(
            $rows->forPage($page, self::PAGE_SIZE)->values(),
            $rows->count(),
            self::PAGE_SIZE,
            $page,
            ['path' => route('admin.panoramas.index'), 'query' => $request->query()],
        );

        return view('admin.panoramas-page', [
            'rows' => $paginator,
            'search' => $search,
            'status' => $status,
            'statuses' => self::STATUSES,
            'total' => Panorama::query()->count(),
            'points' => $this->editorPoints(),
            'maxBytes' => StorePanoramaRequest::maxBytes(),
            'maxSize' => Number::fileSize(StorePanoramaRequest::maxBytes(), maxPrecision: 0),
            // Set when the whole upload was bigger than php.ini's post_max_size (see bootstrap/app.php).
            'tooLarge' => $request->boolean('too_large') ? StorePanoramaRequest::tooBigMessage() : null,
            // Reuse the key after a failed upload so a retry is still the same request.
            'idempotencyKey' => old('idempotency_key', (string) Str::uuid()),
        ]);
    }

    /**
     * The map editor: every photo on the map, one selected (?photo=pn3) and a floor (?floor=2; the selected photo's by default).
     */
    public function map(Request $request): View
    {
        $points = $this->editorPoints();
        $selected = $points->firstWhere('code', (string) $request->query('photo', '')) ?? $points->first();
        $floor = (int) $request->query('floor', $selected['floor'] ?? 1);

        return view('admin.panoramas-map-page', [
            'points' => $points,
            'selected' => $selected['code'] ?? null,
            'floor' => max(1, min(CampusMap::MAX_FLOORS, $floor)),
            'maxFloors' => CampusMap::MAX_FLOORS,
        ]);
    }

    /**
     * Saves several photos at once (JSON). Every photo is checked first: if one fails, none is saved and the errors are
     * sent back by photo (photos.N.field).
     */
    public function saveAll(SavePanoramasRequest $request): JsonResponse
    {
        $attributes = $request->photoAttributes();
        DB::transaction(function () use ($attributes): void {
            foreach ($attributes as $code => $details) {
                Panorama::query()->where('code', $code)->firstOrFail()->update($details);
            }
        });

        return response()->json(['saved' => array_keys($attributes)]);
    }

    /**
     * Upload one photo. It is placed by its GPS when that is on campus, else in the middle of the campus for the admin
     * to drag into place.
     */
    public function store(StorePanoramaRequest $request, PanoramaImages $images, CampusMap $campusMap): RedirectResponse
    {
        $file = (string) $request->file('photo')->getRealPath();
        $gps = $images->position($file);
        $placed = $gps !== null && $campusMap->isNearCampus($gps['latitude'], $gps['longitude']);
        $position = $placed ? $gps : $campusMap->campusCenter();
        if ($position === null) {
            throw ValidationException::withMessages(['photo' => 'The campus map is empty, so the photo has nowhere to go. Load the map first.']);
        }

        $panorama = $this->createWithNextCode(fn (string $code) => $images->create($file, [
            'code' => $code,
            'name' => $request->filled('name') ? $request->string('name')->trim()->toString() : 'Photo point '.substr($code, strlen(Panorama::CODE_PREFIX)),
            ...$position,
            'floor' => 1,
        ]));

        $redirect = redirect()->route('admin.panoramas.map', ['photo' => $panorama->code])
            ->with('status', "{$panorama->name} was uploaded. Check its position, then set north.");

        return $placed ? $redirect : $redirect->with('warning', $gps === null
            ? 'This photo has no GPS position, so it was put in the middle of the campus. Drag the pin to where it was taken.'
            : 'The GPS position in this photo is not on campus, so it was put in the middle of the campus. Drag the pin to where it was taken.');
    }

    /** The old one-photo edit page: the map editor, with this photo selected. */
    public function edit(Panorama $panorama): RedirectResponse
    {
        return redirect()->route('admin.panoramas.map', ['photo' => $panorama->code], 301);
    }

    /**
     * Every photo point, hidden ones too, for the debug panel on /navigate?debug=1: it lists the photos on the route
     * and why the others are skipped. GET /api/map only has the photos shown to users.
     */
    public function routeDebug(): JsonResponse
    {
        return response()->json(['data' => $this->inCodeOrder(Panorama::query()->get())->map(fn (Panorama $point) => [
            'id' => $point->code,
            'name' => $point->name,
            'floor' => $point->floor,
            'north_offset' => $point->north_offset,
            'is_visible' => $point->is_visible,
            'coord' => [$point->longitude, $point->latitude],
        ])->values()]);
    }

    public function update(UpdatePanoramaRequest $request, Panorama $panorama): RedirectResponse
    {
        $panorama->update($request->panoramaAttributes());

        return redirect()->route('admin.panoramas.index')->with('status', "{$panorama->name} was saved.");
    }

    public function visibility(UpdatePanoramaVisibilityRequest $request, Panorama $panorama): RedirectResponse
    {
        $panorama->update(['is_visible' => $request->boolean('is_visible')]);

        return redirect()->route('admin.panoramas.index')->with('status', $panorama->is_visible
            ? "{$panorama->name} is now shown to users."
            : "{$panorama->name} is now hidden from users.");
    }

    public function destroy(Panorama $panorama): RedirectResponse
    {
        $panorama->delete();

        return redirect()->route('admin.panoramas.index')->with('status', "{$panorama->name} was deleted.");
    }

    /** Meters from each photo to the nearest walkway on its floor (null: that floor has none), by code. */
    private function distancesFromWalkways(CampusMap $campusMap): \Illuminate\Support\Collection
    {
        return Panorama::query()->get()->mapWithKeys(fn (Panorama $panorama) => [
            $panorama->code => $campusMap->metersToNearestPath($panorama->latitude, $panorama->longitude, $panorama->floor),
        ]);
    }

    /** A photo gets the "Check position" badge when no walkway is within CHECK_POSITION_METERS of it. */
    private function needsCheck(?float $meters): bool
    {
        return $meters === null || $meters > self::CHECK_POSITION_METERS;
    }

    /**
     * Every photo (hidden ones too) for the editor and the list's preview: where it is, its details and its images.
     *
     * @return Collection<int, array<string, mixed>>
     */
    private function editorPoints(): \Illuminate\Support\Collection
    {
        return $this->inCodeOrder(Panorama::query()->get())->map(fn (Panorama $point) => [
            'code' => $point->code,
            'name' => $point->name,
            'floor' => $point->floor,
            'latitude' => $point->latitude,
            'longitude' => $point->longitude,
            'north_offset' => $point->north_offset,
            'is_visible' => $point->is_visible,
            'image' => $point->imageUrl(),
            'thumb' => $point->thumbUrl(),
        ])->values();
    }

    /**
     * @param  Collection<int, Panorama>  $panoramas
     * @return Collection<int, Panorama>
     */
    private function inCodeOrder(Collection $panoramas): Collection
    {
        return $panoramas->sortBy(fn (Panorama $panorama) => CampusMap::codeNumber($panorama->code))->values();
    }

    /**
     * @param  callable(string): Panorama  $create
     */
    private function createWithNextCode(callable $create): Panorama
    {
        for ($attempt = 1; ; $attempt++) {
            try {
                return $create(Panorama::nextCode());
            } catch (UniqueConstraintViolationException $exception) {
                if ($attempt >= self::CODE_ATTEMPTS) {
                    throw $exception;
                }
            }
        }
    }
}
