<?php

namespace App\Services;

/**
 * One QR code: a room, or one door of a building. `from` is the walk-graph place id that /navigate?from= and the
 * app's Scan QR read; null when directions can't start here yet, and `reason` says why.
 */
final readonly class PlaceQrCode
{
    public function __construct(
        /** Big name on the card, in the words students use ("NEE 202", "Library"). */
        public string $title,
        /** Second line: "NEE Building · 2nd floor", "Door of ComLab 1". */
        public string $detail,
        public ?string $from,
        public ?string $url,
        public ?string $reason = null,
    ) {}

    public function printable(): bool
    {
        return $this->url !== null;
    }
}
