<?php

namespace App\Services;

use App\Models\Building;
use App\Models\Room;
use chillerlan\QRCode\Common\EccLevel;
use chillerlan\QRCode\QRCode;
use chillerlan\QRCode\QROptions;
use Illuminate\Support\Number;
use Illuminate\Support\Str;

/**
 * The "QR code" button on Admin > Rooms and Admin > Buildings (docs/navigation.md "QR codes"). A code holds
 * <APP_URL>/navigate?from=<place id>, the link /navigate (main.js ?from=) and the app's Scan QR (places.ts readQr)
 * both start directions from. Place ids are the walk graph's (graph.js listPlaces): a room's code is its door. A
 * building has no place of its own: like the router, which goes to a building's nearest ground-floor door, its
 * entrances are the doors of its ground-floor rooms.
 */
class PlaceQrCodes
{
    /**
     * A room's code. Upstairs, directions only reach a room when its building's stairs go up to that floor
     * (graph.js addRooms); without them there is no code yet, and `reason` says why.
     */
    public function forRoom(Room $room): PlaceQrCode
    {
        $topStairFloor = (int) $room->building->stairs()->max('floors');
        $reason = $room->floor > 1 && $topStairFloor < $room->floor
            ? 'No stairs on the map go up to the '.Number::ordinal($room->floor).' floor of '.$room->buildingLabel().' yet, so directions can’t start from this room. Once the stairs are added in the map file, its QR code is ready.'
            : null;

        return $this->code($room->name, $room->locationLabel(), $room->code, $reason);
    }

    /**
     * A building's entrances: one code per ground-floor room door, by room name. Empty when it has none yet.
     *
     * @return list<PlaceQrCode>
     */
    public function entrances(Building $building): array
    {
        return $building->rooms()->where('floor', 1)->get()
            ->sort(fn (Room $a, Room $b) => strnatcasecmp($a->name, $b->name))
            ->map(fn (Room $room) => $this->code($building->knownName(), 'Door of '.($room->door ?: $room->name), $room->code))
            ->values()->all();
    }

    /**
     * The link a code holds: APP_URL (the public site) + /navigate?from=<place id>. Built from the config, not the
     * request, so codes printed from any address open the real site.
     */
    public function url(string $from): string
    {
        return rtrim((string) config('app.url'), '/').'/navigate?'.http_build_query(['from' => $from]);
    }

    /**
     * Whether APP_URL is a public https address. Codes made with localhost or a LAN address can't be opened on
     * campus, so the page warns before printing.
     */
    public function hasPublicUrl(): bool
    {
        $url = (string) config('app.url');
        $host = (string) parse_url($url, PHP_URL_HOST);

        if (! str_starts_with($url, 'https://') || $host === '' || $host === 'localhost' || Str::endsWith($host, ['.test', '.local', '.localhost'])) {
            return false;
        }
        if (filter_var($host, FILTER_VALIDATE_IP) !== false) {
            return filter_var($host, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) !== false;
        }

        return true;
    }

    /**
     * The QR code as an inline SVG (black on transparent, with the quiet zone), error correction M: it still reads
     * with a scuffed corner, and the code stays small enough to scan from about a meter away.
     */
    public function svg(string $url): string
    {
        $options = new QROptions([
            'outputBase64' => false,
            'svgAddXmlHeader' => false,
            'eccLevel' => EccLevel::M,
            'connectPaths' => true,
            'drawLightModules' => false,
        ]);

        return (new QRCode($options))->render($url);
    }

    private function code(string $title, string $detail, string $from, ?string $reason = null): PlaceQrCode
    {
        return new PlaceQrCode($title, $detail, $reason ? null : $from, $reason ? null : $this->url($from), $reason);
    }
}
