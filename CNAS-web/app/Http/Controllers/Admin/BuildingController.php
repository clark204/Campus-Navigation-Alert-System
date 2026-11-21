<?php

namespace App\Http\Controllers\Admin;

use App\Enums\BuildingType;
use App\Enums\PhotoKind;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\StorePlacePhotosRequest;
use App\Http\Requests\Admin\UpdateBuildingRequest;
use App\Models\Building;
use App\Models\PlacePhoto;
use App\Services\CampusMap;
use App\Services\PlacePhotos;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;
use RuntimeException;

/**
 * Admin "Buildings": the real places on the map with their names and photos. Shapes, floors and type come from the
 * other app (campus.geojson). The campus outline is not a place, so it has no page.
 */
class BuildingController extends Controller
{
    /** Buildings per page on the list. */
    public const PAGE_SIZE = 10;

    public function index(): View
    {
        $buildings = Building::query()
            ->where('type', '!=', BuildingType::Campus)
            ->with('photos')
            ->get()
            ->sortBy(fn (Building $building) => CampusMap::codeNumber($building->code))
            ->values();

        // Every row is in the page: the list searches and pages it in the browser (table-search.js).
        return view('admin.buildings-page', [
            'buildings' => $buildings,
            'total' => $buildings->count(),
            'withPhotos' => $buildings->filter(fn (Building $building) => $building->photos->isNotEmpty())->count(),
            'maxPhotos' => PlacePhotos::MAX_PER_PLACE,
        ]);
    }

    public function edit(Building $building): View
    {
        abort_if($building->type === BuildingType::Campus, 404);

        return view('admin.building-edit-page', [
            'building' => $building->load('photos'),
            'maxPhotos' => PlacePhotos::MAX_PER_PLACE,
        ]);
    }

    public function update(UpdateBuildingRequest $request, Building $building): RedirectResponse
    {
        abort_if($building->type === BuildingType::Campus, 404);

        $building->update($request->validated());

        return to_route('admin.buildings.index')->with('status', "{$building->name} was saved.");
    }

    public function storePhotos(StorePlacePhotosRequest $request, Building $building, PlacePhotos $photos): RedirectResponse
    {
        abort_if($building->type === BuildingType::Campus, 404);

        $kind = $request->enum('kind', PhotoKind::class);
        $unreadable = 0;
        foreach ($request->file('photos') as $file) {
            try {
                $photos->add($building, $kind, $file->getRealPath());
            } catch (RuntimeException) {
                $unreadable++;
            }
        }

        return to_route('admin.buildings.edit', $building)->with(
            $unreadable === 0 ? 'status' : 'warning',
            $unreadable === 0 ? 'Photos added.' : "{$unreadable} photo(s) could not be read and were not added.",
        );
    }

    public function movePhoto(Request $request, Building $building, PlacePhoto $photo, PlacePhotos $photos): RedirectResponse
    {
        abort_if($building->type === BuildingType::Campus, 404);

        $direction = $request->validate(['direction' => ['required', Rule::in(['up', 'down'])]])['direction'];
        $photos->move($building, $photo, $direction);

        return to_route('admin.buildings.edit', $building);
    }

    public function destroyPhoto(Building $building, PlacePhoto $photo): RedirectResponse
    {
        abort_if($building->type === BuildingType::Campus, 404);

        $photo->delete();

        return to_route('admin.buildings.edit', $building)->with('status', 'Photo deleted.');
    }
}
