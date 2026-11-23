<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Building;
use App\Models\Room;
use App\Services\PlaceQrCode;
use App\Services\PlaceQrCodes;
use Illuminate\View\View;

/**
 * The "QR code" button on Admin > Rooms and Admin > Buildings: one page with the place's printable card, Print and
 * Download PNG. Scanning the code starts directions from that spot on /navigate and in the app
 * (docs/navigation.md "QR codes").
 */
class QrCodeController extends Controller
{
    public function room(Room $room, PlaceQrCodes $codes): View
    {
        $room->loadMissing('building');
        $code = $codes->forRoom($room);

        return $this->page($codes, [$code], $room->name, $code->reason, route('admin.rooms.index'), 'Rooms');
    }

    /**
     * A building's code starts at one of its doors (ground-floor rooms). With several, the page lets the admin pick
     * the door the code will be posted at; the main entrance is picked first.
     */
    public function building(Building $building, PlaceQrCodes $codes): View
    {
        $doors = $codes->entrances($building);
        $reason = $doors === []
            ? 'This building has no ground-floor room on the map yet. Directions start at a room’s door, so its QR code will be ready once a ground-floor room is added in the map file.'
            : null;

        return $this->page($codes, $doors, $building->knownName(), $reason, route('admin.buildings.index'), 'Buildings');
    }

    /**
     * @param  list<PlaceQrCode>  $codes
     */
    private function page(PlaceQrCodes $service, array $codes, string $name, ?string $reason, string $backUrl, string $backLabel): View
    {
        $printable = array_values(array_filter($codes, fn (PlaceQrCode $code) => $code->printable()));

        return view('admin.qr-code-page', [
            'name' => $name,
            'reason' => $reason,
            'cards' => array_map(fn (PlaceQrCode $code) => ['code' => $code, 'svg' => $service->svg($code->url)], $printable),
            'publicUrl' => $service->hasPublicUrl(),
            'appUrl' => config('app.url'),
            'backUrl' => $backUrl,
            'backLabel' => $backLabel,
        ]);
    }
}
