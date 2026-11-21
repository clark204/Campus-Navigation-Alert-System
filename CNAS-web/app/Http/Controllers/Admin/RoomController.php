<?php

namespace App\Http\Controllers\Admin;

use App\Enums\PhotoKind;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\StorePlacePhotosRequest;
use App\Http\Requests\Admin\UpdateRoomRequest;
use App\Models\Building;
use App\Models\PlacePhoto;
use App\Models\Room;
use App\Services\CampusMap;
use App\Services\PlacePhotos;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;
use RuntimeException;

/**
 * Admin "Rooms": every room on the map, filterable by building, with its name and photos. Codes, buildings, floors,
 * types and positions come from the other app (campus.geojson).
 */
class RoomController extends Controller
{
    /** Rooms per page on the list. */
    public const PAGE_SIZE = 10;

    public function index(Request $request): View
    {
        $building = (string) $request->query('building', '');

        $all = Room::query()
            ->with(['building', 'photos'])
            ->get()
            ->sortBy(fn (Room $room) => sprintf(
                '%06d-%03d-%06d',
                CampusMap::codeNumber($room->building->code),
                $room->floor,
                CampusMap::codeNumber($room->code),
            ))
            ->values();

        // The building filter is a page link (?building=b8); the search runs in the browser over the rows it returns.
        $rooms = $all->filter(fn (Room $room) => $building === '' || $room->building->code === $building)->values();

        return view('admin.rooms-page', [
            'rooms' => $rooms,
            'total' => $all->count(),
            // Only buildings with rooms get a filter chip, each with its room count.
            'buildings' => Building::query()
                ->has('rooms')
                ->withCount('rooms')
                ->get()
                ->sortBy(fn (Building $place) => CampusMap::codeNumber($place->code))
                ->values(),
            'selectedBuilding' => $building,
            'maxPhotos' => PlacePhotos::MAX_PER_PLACE,
        ]);
    }

    public function edit(Room $room): View
    {
        return view('admin.room-edit-page', [
            'room' => $room->load(['building', 'photos']),
            'maxPhotos' => PlacePhotos::MAX_PER_PLACE,
        ]);
    }

    public function update(UpdateRoomRequest $request, Room $room): RedirectResponse
    {
        $room->update($request->validated());

        return to_route('admin.rooms.index')->with('status', "{$room->name} was saved.");
    }

    public function storePhotos(StorePlacePhotosRequest $request, Room $room, PlacePhotos $photos): RedirectResponse
    {
        $kind = $request->enum('kind', PhotoKind::class);
        $unreadable = 0;
        foreach ($request->file('photos') as $file) {
            try {
                $photos->add($room, $kind, $file->getRealPath());
            } catch (RuntimeException) {
                $unreadable++;
            }
        }

        return to_route('admin.rooms.edit', $room)->with(
            $unreadable === 0 ? 'status' : 'warning',
            $unreadable === 0 ? 'Photos added.' : "{$unreadable} photo(s) could not be read and were not added.",
        );
    }

    public function movePhoto(Request $request, Room $room, PlacePhoto $photo, PlacePhotos $photos): RedirectResponse
    {
        $direction = $request->validate(['direction' => ['required', Rule::in(['up', 'down'])]])['direction'];
        $photos->move($room, $photo, $direction);

        return to_route('admin.rooms.edit', $room);
    }

    public function destroyPhoto(Room $room, PlacePhoto $photo): RedirectResponse
    {
        $photo->delete();

        return to_route('admin.rooms.edit', $room)->with('status', 'Photo deleted.');
    }
}
