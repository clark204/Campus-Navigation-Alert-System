import { ordinal, type Navigation, type Place, type RouteOptions } from './engine';

/**
 * Where a trip goes. `targets` is one graph node or a set of them (the nearest reachable one wins, router.js).
 * - place: a gate or a room, as on the web's destination picker
 * - building: its nearest ground-floor door
 * - evacuation (next step, CNAS-web navigation/evacuation.js): the edge of the evacuation area, with `options` keeping
 *   the route off walkways in the danger zone and no pin at the end (the area itself is highlighted)
 * `options` is asked again on every reroute, so it can follow an alert that changes.
 */
export type Destination = {
  kind: 'place' | 'building' | 'evacuation';
  id: string;
  /** Spoken and shown: "NEE 202", "the Library". Never a code. */
  name: string;
  targets: string | Set<string>;
  place?: Place;
  options?: () => RouteOptions;
  showPin?: boolean;
};

export type SearchResult = { key: string; title: string; detail: string; icon: 'pin' | 'home' | 'map'; destination: Destination };

const ROOM_WORDS: Record<string, string> = {
  classroom: 'classroom room',
  lab: 'lab laboratory computer',
  office: 'office',
  cr: 'cr comfort room restroom toilet bathroom',
};

/** "the Library" → "Library" (the web's titleCase): landmark names are written for directions. */
export function titleCase(name: string): string {
  const plain = name.trim().replace(/^the\s+/i, '');
  return plain.charAt(0).toUpperCase() + plain.slice(1);
}

/** The name students use for a building: its landmark name, else its name. */
export function buildingName(navigation: Navigation, buildingId: string | undefined): string | null {
  if (!buildingId) return null;
  const landmark = navigation.data.landmarks.buildings[buildingId];
  if (landmark) return titleCase(landmark);
  const feature = navigation.data.buildings.features.find((f) => f.properties.id === buildingId);
  return feature ? titleCase(feature.properties.name) : null;
}

export const placeDestination = (place: Place): Destination => ({ kind: 'place', id: place.id, name: place.name, targets: place.nodeId, place });

/** What a place is shown as under its name: "2nd floor · Engineering Building", "Gate". */
export function placeDetail(navigation: Navigation, place: Place): string {
  if (place.kind === 'gate') return 'Gate';
  return [place.floor > 1 ? `${ordinal(place.floor)} floor` : 'Ground floor', buildingName(navigation, place.buildingId)].filter(Boolean).join(' · ');
}

const normalize = (text: string) => text.toLowerCase().replace(/[^a-z0-9]+/g, ' ').trim();

type Entry = { result: SearchResult; name: string; words: string };

const indexes = new WeakMap<Navigation, Entry[]>();

function index(navigation: Navigation): Entry[] {
  let entries = indexes.get(navigation);
  if (entries) return entries;
  entries = [];
  const roomType = new Map(navigation.data.entrances.features.map((f) => [f.properties.id as string, f.properties.type as string]));

  for (const place of navigation.places) {
    const building = buildingName(navigation, place.buildingId) ?? '';
    const type = ROOM_WORDS[roomType.get(place.id) ?? ''] ?? '';
    const detail = placeDetail(navigation, place);
    entries.push({
      result: { key: place.id, title: place.name, detail, icon: place.kind === 'gate' ? 'map' : 'pin', destination: placeDestination(place) },
      name: normalize(place.name),
      words: normalize(`${place.name} ${building} ${type} ${place.kind === 'gate' ? 'gate entrance exit' : ''} ${place.floor > 1 ? `${ordinal(place.floor)} floor` : 'ground floor'}`),
    });
  }

  // A building goes to its nearest ground-floor door (rooms on the ground floor link to the walkways).
  for (const feature of navigation.data.buildings.features) {
    const { id, type } = feature.properties;
    if (type !== 'building' && type !== 'facility') continue;
    const doors = navigation.places.filter((p) => p.kind === 'room' && p.buildingId === id && p.floor === 1).map((p) => p.nodeId);
    const name = buildingName(navigation, id);
    if (!doors.length || !name) continue;
    const rooms = navigation.places.filter((p) => p.kind === 'room' && p.buildingId === id).length;
    entries.push({
      result: {
        key: `building:${id}`,
        title: name,
        detail: `Building · ${rooms} ${rooms === 1 ? 'room' : 'rooms'}`,
        icon: 'home',
        destination: { kind: 'building', id, name: navigation.data.landmarks.buildings[id]?.trim() || name, targets: new Set(doors) },
      },
      name: normalize(name),
      words: normalize(`${name} ${feature.properties.name} building`),
    });
  }
  indexes.set(navigation, entries);
  return entries;
}

/**
 * Places matching what was typed, best first. Every word typed must start a word of the place (its name, its
 * building's name, its kind: "cr", "lab", "gate"). Codes are never shown or matched.
 */
export function searchPlaces(navigation: Navigation, query: string, limit = 30): SearchResult[] {
  const words = normalize(query).split(' ').filter(Boolean);
  const entries = index(navigation);
  if (!words.length) return [];
  const scored: { entry: Entry; score: number }[] = [];
  for (const entry of entries) {
    const haystack = ` ${entry.words}`;
    if (!words.every((word) => haystack.includes(` ${word}`))) continue;
    const typed = words.join(' ');
    const score = entry.name === typed ? 0 : entry.name.startsWith(typed) ? 1 : ` ${entry.name}`.includes(` ${words[0]}`) ? 2 : 3;
    scored.push({ entry, score });
  }
  return scored
    .sort((a, b) => a.score - b.score || a.entry.result.title.localeCompare(b.entry.result.title, undefined, { numeric: true }))
    .slice(0, limit)
    .map(({ entry }) => entry.result);
}

export type ResultGroup = { title: string; results: SearchResult[] };

/**
 * Every place, with nothing typed: the same list and order as the web's start and destination pickers (main.js
 * fillPicker): Gates, then each building's rooms (buildings by name; rooms by floor, then name).
 */
export function browsePlaces(navigation: Navigation): ResultGroup[] {
  const byKey = new Map(index(navigation).map((entry) => [entry.result.key, entry.result]));
  const resultFor = (place: Place) => byKey.get(place.id)!;
  const gates = navigation.places.filter((p) => p.kind === 'gate').map(resultFor);

  const byBuilding = new Map<string | undefined, Place[]>();
  for (const place of navigation.places) {
    if (place.kind !== 'room') continue;
    byBuilding.set(place.buildingId, [...(byBuilding.get(place.buildingId) ?? []), place]);
  }
  const buildings = [...byBuilding].map(([id, rooms]) => ({
    title: buildingName(navigation, id) ?? 'Other rooms',
    results: rooms
      .sort((a, b) => a.floor - b.floor || a.name.localeCompare(b.name, undefined, { numeric: true }))
      .map(resultFor),
  }));
  buildings.sort((a, b) => a.title.localeCompare(b.title, undefined, { numeric: true }));
  return [...(gates.length ? [{ title: 'Gates', results: gates }] : []), ...buildings];
}

/** A few places to start from with nothing typed: the buildings, by name. */
export function suggestedPlaces(navigation: Navigation): SearchResult[] {
  return index(navigation)
    .filter((entry) => entry.result.destination.kind === 'building')
    .map((entry) => entry.result)
    .sort((a, b) => a.title.localeCompare(b.title, undefined, { numeric: true }));
}

/**
 * The place a CNAS QR code names. The codes posted on campus hold the web's links, /navigate?from=<place id> (and
 * maybe &to=); a bare place id also works. Null when it isn't a CNAS place.
 */
export function readQr(navigation: Navigation, data: string): { from: Place | null; to: Place | null } | null {
  const text = data.trim();
  let from: string | null = null;
  let to: string | null = null;
  const query = text.includes('?') ? text.slice(text.indexOf('?') + 1).split('#')[0] : null;
  if (query !== null) {
    for (const pair of query.split('&')) {
      const [key, value = ''] = pair.split('=');
      const decoded = decodeURIComponent(value.replace(/\+/g, ' '));
      if (key === 'from') from = decoded;
      if (key === 'to') to = decoded;
    }
  } else if (/^[\w-]+$/.test(text)) {
    from = text;
  }
  const fromPlace = from ? (navigation.placeById.get(from) ?? null) : null;
  const toPlace = to ? (navigation.placeById.get(to) ?? null) : null;
  return fromPlace || toPlace ? { from: fromPlace, to: toPlace } : null;
}
