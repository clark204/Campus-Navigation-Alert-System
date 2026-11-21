<?php

namespace App\Services;

use App\Enums\BuildingType;
use App\Models\Building;
use App\Models\Path;
use App\Models\Room;
use App\Models\Stair;
use Illuminate\Database\Eloquent\Model;
use InvalidArgumentException;

/**
 * Gives every feature of a campus.geojson its code, so nobody has to type one in JOSM.
 *
 * - A feature with codes keeps them. A stacked room's codes must already be on the map: a new room is added one at a
 *   time, not stacked.
 * - A feature with no code was drawn or redrawn in JOSM. It is matched by name (ignoring capitals and extra spaces) to
 *   an existing feature of the same kind whose code no other feature in the file uses:
 *   - exactly one match: it is that feature, redrawn, and keeps its code;
 *   - no match: it is new and gets the next free code for its kind (b, p, r or s, then the next number);
 *   - more than one match: it is added as new and flagged, so the admin can check for a duplicate.
 */
class CampusMapCodes
{
    /** @var array<string, class-string<Model>> */
    private const MODELS = ['building' => Building::class, 'path' => Path::class, 'room' => Room::class, 'stair' => Stair::class];

    /** @var array<string, string> */
    private const LETTER = ['building' => 'b', 'path' => 'p', 'room' => 'r', 'stair' => 's'];

    /**
     * @param  list<array<string, mixed>>  $entries  from CampusMapFeatureReader::read()
     * @return list<array<string, mixed>> each entry with its 'codes' (one for a feature, one per room for stacked rooms),
     *                                    its 'name' as it is on the map, and whether it was 'matched' or flagged as a
     *                                    'duplicate'.
     *
     * @throws InvalidArgumentException when a stacked room is not on the map yet, or a feature has no code and no name.
     */
    public function assign(array $entries): array
    {
        $existing = $this->existing();
        $used = [];
        $highest = [];
        foreach ($existing as $kind => $rows) {
            $highest[$kind] = max([0, ...array_map(fn (array $row) => CampusMap::codeNumber($row['code']), $rows)]);
        }
        foreach ($entries as $entry) {
            foreach ($entry['ids'] as $id) {
                $used[$id] = true;
                $highest[$entry['kind']] = max($highest[$entry['kind']], CampusMap::codeNumber($id));
            }
        }

        $placed = [];
        foreach ($entries as $entry) {
            $kind = $entry['kind'];

            if ($entry['ids'] !== []) {
                if ($entry['stacked']) {
                    $this->mustBeOnMap($entry, $existing['room']);
                }
                $placed[] = [...$entry, 'codes' => $entry['ids'], 'matched' => false, 'duplicate' => false];

                continue;
            }

            if ($entry['name'] === null) {
                throw new InvalidArgumentException("{$entry['label']} has no code and no name, so it can't be matched to a feature. Give it a name in JOSM.");
            }

            $key = $this->key($entry['name']);
            $matches = array_values(array_filter($existing[$kind], fn (array $row) => $row['matchable']
                && $this->key($row['name']) === $key
                && ! isset($used[$row['code']])));

            if (count($matches) === 1) {
                $code = $matches[0]['code'];
                $used[$code] = true;
                $placed[] = [...$entry, 'codes' => [$code], 'name' => $matches[0]['name'], 'matched' => true, 'duplicate' => false];

                continue;
            }

            $highest[$kind]++;
            $code = self::LETTER[$kind].$highest[$kind];
            $used[$code] = true;
            $placed[] = [...$entry, 'codes' => [$code], 'matched' => false, 'duplicate' => count($matches) > 1];
        }

        return $placed;
    }

    /**
     * Every row of each kind: its code, its name, and whether it can be matched by name (the campus outline cannot).
     *
     * @return array<string, list<array{code: string, name: string, matchable: bool}>>
     */
    private function existing(): array
    {
        $rows = [];
        foreach (self::MODELS as $kind => $model) {
            $rows[$kind] = $model::query()->get()->map(fn (Model $row) => [
                'code' => (string) $row->code,
                'name' => (string) $row->name,
                'matchable' => ! ($row instanceof Building && $row->type === BuildingType::Campus),
            ])->all();
        }

        return $rows;
    }

    /**
     * @param  list<array{code: string, name: string, matchable: bool}>  $rooms
     */
    private function mustBeOnMap(array $entry, array $rooms): void
    {
        $codes = array_column($rooms, 'code');
        foreach ($entry['ids'] as $id) {
            if (! in_array($id, $codes, true)) {
                throw new InvalidArgumentException("{$entry['label']}: stacked rooms must be added one at a time. {$id} is not on the map yet.");
            }
        }
    }

    /**
     * Names compare without capitals and without extra spaces.
     */
    private function key(string $name): string
    {
        return mb_strtolower((string) preg_replace('/\s+/u', ' ', trim($name)));
    }
}
