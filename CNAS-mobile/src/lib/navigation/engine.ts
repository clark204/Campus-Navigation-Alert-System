// The navigation engine, shared with the website: CNAS-web resources/js/navigation (see metro.config.js). This file is
// the only place that imports it, and adds the TypeScript types the app uses. Nothing here changes how a route is
// found or followed, so the app and /navigate give the same directions for the same start and destination.
import { buildManeuvers as buildManeuversJs, doorHeading as doorHeadingJs, ordinal as ordinalJs } from '@nav/instructions.js';
import { attachPoint as attachPointJs, buildGraph as buildGraphJs, listPlaces as listPlacesJs, nearestOnEdges as nearestOnEdgesJs } from '@nav/graph.js';
import { createFixFilter as createFixFilterJs, GPS_MAX_ACCURACY as GPS_MAX_ACCURACY_JS } from '@nav/gps.js';
import { currentLeg as currentLegJs, floorParts as floorPartsJs, legIntro as legIntroJs, legLabel as legLabelJs, legLine as legLineJs, routeLegs as routeLegsJs } from '@nav/legs.js';
import { drawInFraction as drawInFractionJs, glidePoint as glidePointJs, GLIDE_MS as GLIDE_MS_JS, partialLines as partialLinesJs } from '@nav/motion.js';
import { meters as metersJs } from '@nav/geo.js';
import { movePoint as movePointJs, projectOnRoute as projectOnRouteJs } from '@nav/position.js';
import { findRoute as findRouteJs } from '@nav/router.js';
import { createSimulator as createSimulatorJs, SIM_INTERVAL_MS as SIM_INTERVAL_MS_JS, CAUGHT_UP_METERS as CAUGHT_UP_METERS_JS } from '@nav/simulate.js';
import { createStepCounter as createStepCounterJs, DEFAULT_STRIDE as DEFAULT_STRIDE_JS, DEFAULT_THRESHOLD as DEFAULT_THRESHOLD_JS } from '@nav/steps.js';
import { OFF_ROUTE_METERS as OFF_ROUTE_METERS_JS, Tracker as TrackerJs } from '@nav/tracker.js';
import { createPromptPlanner as createPromptPlannerJs } from '@nav/voice.js';
import { VOICE_DIRECTIONS_DEFAULT as VOICE_DIRECTIONS_DEFAULT_JS } from '@nav/constants.js';

import type { CampusMap, NavData } from '../campus-map';

export type LngLat = [number, number];

export type GraphNode = {
  id: string;
  coord: LngLat;
  floor: number;
  kind: 'path' | 'room' | 'stair';
  paths: Set<string>;
  name?: string;
  buildingId?: string;
  stairId?: string;
  roomType?: string;
};

export type GraphEdge = { id: string; a: string; b: string; weight: number; type: 'walkway' | 'gate' | 'link' | 'stairs'; path: string | null };

export type Graph = { nodes: Map<string, GraphNode>; edges: Map<string, GraphEdge>; adjacency: Map<string, Set<string>> };

export type LineFeature = { type: 'Feature'; properties: object; geometry: { type: 'LineString'; coordinates: LngLat[] } };

export type Route = { nodes: GraphNode[]; edges: GraphEdge[]; along: number[]; length: number; cost: number; line: LineFeature };

export type Maneuver = {
  type: 'depart' | 'turn' | 'stairs' | 'arrive';
  modifier: string;
  along: number;
  nodeId: string;
  text: string;
  ahead: string | null;
  now: string;
  index: number;
  legBefore: number;
  bearingAfter?: number;
  fromFloor?: number;
  toFloor?: number;
  stairsName?: string;
};

export type Leg = {
  index: number;
  floor: number;
  from: number;
  to: number;
  stairs: Maneuver | null;
  target: { kind: 'stairs' | 'destination'; coord: LngLat };
  destination: { name: string; floor: number };
};

/** A gate or a room, as the web's pickers list them (graph.js listPlaces). */
export type Place = { id: string; name: string; kind: 'gate' | 'room'; floor: number; buildingId?: string; nodeId: string };

export type Heading = { value: number | null; source: string };

/** A Geolocation-shaped fix, what the tracker and the GPS filter read (location.ts makes them from expo-location). */
export type Fix = {
  coords: { longitude: number; latitude: number; accuracy: number; speed: number | null; heading: number | null };
  timestamp: number;
};

export type FixFilter = {
  coord: LngLat | null;
  heading: Heading;
  uncertainty: number;
  reset: (coord: LngLat) => void;
};

export type GpsStatus = {
  accuracy: number;
  speed: number | null;
  moving: boolean;
  used: boolean;
  reason: string;
  offRoute: number;
  rerouteDue: boolean;
  wrongWayStarted: boolean;
};

/** tracker.js: follows the person along a route. */
export type Tracker = {
  route: Route;
  maneuvers: Maneuver[];
  progress: number;
  steps: number;
  lastStepAt: number;
  graceFixes: number;
  offRoute: number;
  offRouteStreak: number;
  wrongWay: boolean;
  waitingAt: Maneuver | null;
  confirmedStairs: Set<number>;
  source: string;
  filter: FixFilter;
  lastGps: GpsStatus | null;
  arriveManeuver: Maneuver;
  onChange: ((tracker: Tracker) => void) | null;
  readonly length: number;
  readonly remaining: number;
  readonly arrived: boolean;
  readonly next: Maneuver;
  readonly distanceToNext: number;
  readonly heading: Heading;
  readonly free: boolean;
  floorAt: (at?: number) => number;
  section: () => { from: number; to: number };
  routeBearing: (at?: number) => number | null;
  gps: (fix: Fix, options?: { recentSteps?: boolean | null }) => GpsStatus;
  step: (options?: { now?: number }) => { moved: boolean; reason: string };
  setProgress: (meters: number) => void;
  clearWrongWay: () => void;
  imHere: () => void;
  confirmFloor: () => void;
  position: (at?: number) => LngLat;
  remainingLine: () => LineFeature;
};

export type TrackerOptions = {
  stride: number;
  graph: Graph | null;
  filter?: FixFilter | null;
  graceFixes?: number;
  startHeading?: Heading | null;
};

export type PromptPlanner = {
  next: (progress: number, heading?: number | null) => { key: string; text: string } | null;
  markSaid: (key: string) => void;
  said: Set<string>;
};

/** Edge prices for the router: Infinity blocks an edge (a walkway inside an emergency's danger zone). */
export type RouteOptions = { cost?: (edge: GraphEdge) => number } | undefined;

export type Simulator = {
  readonly coord: LngLat | null;
  readonly mode: string;
  readonly busy: boolean;
  place: (coord: LngLat) => void;
  walk: (coords: LngLat[]) => void;
  walkTo: (coord: LngLat) => void;
  stop: () => void;
  tick: () => void;
};

export const GPS_MAX_ACCURACY: number = GPS_MAX_ACCURACY_JS;
export const OFF_ROUTE_METERS: number = OFF_ROUTE_METERS_JS;
export const DEFAULT_STRIDE: number = DEFAULT_STRIDE_JS;
export const DEFAULT_THRESHOLD: number = DEFAULT_THRESHOLD_JS;
export const GLIDE_MS: number = GLIDE_MS_JS;
export const SIM_INTERVAL_MS: number = SIM_INTERVAL_MS_JS;
export const CAUGHT_UP_METERS: number = CAUGHT_UP_METERS_JS;
/** Whether spoken directions start on before the person picks (off: the web's default too). */
export const VOICE_DIRECTIONS_DEFAULT: boolean = VOICE_DIRECTIONS_DEFAULT_JS;

const js = <T>(fn: unknown) => fn as T;

export const buildGraph = js<(data: NavData, options?: { warn?: (message: string) => void }) => Graph>(buildGraphJs);
export const listPlaces = js<(graph: Graph, paths: NavData['paths']) => Place[]>(listPlacesJs);
export const attachPoint = js<(graph: Graph, coord: LngLat) => { nodeId: string; distance: number } | null>(attachPointJs);
export const nearestOnEdges = js<(graph: Graph, coord: LngLat) => { point: LngLat; distance: number; edge: GraphEdge } | null>(nearestOnEdgesJs);
export const findRoute = js<(graph: Graph, from: string, to: string | Set<string>, options?: RouteOptions) => Route | null>(findRouteJs);
export const buildManeuvers = js<
  (
    route: Route,
    graph: Graph,
    options: {
      landmarks: NavData['landmarks'];
      buildings: NavData['buildings'];
      originName?: string;
      destinationName?: string;
      heading?: number | null;
    },
  ) => Maneuver[]
>(buildManeuversJs);
export const doorHeading = js<(route: Route) => number | null>(doorHeadingJs);
export const ordinal = js<(n: number) => string>(ordinalJs);
export const routeLegs = js<(route: Route, maneuvers: Maneuver[], options: { destinationName: string }) => Leg[]>(routeLegsJs);
export const currentLeg = js<(legs: Leg[], tracker: Tracker) => Leg>(currentLegJs);
export const legIntro = js<(legs: Leg[], leg: Leg) => string | null>(legIntroJs);
export const legLabel = js<(legs: Leg[], leg: Leg) => string>(legLabelJs);
export const legLine = js<(route: Route, leg: Leg, progress: number) => LineFeature>(legLineJs);
export const floorParts = js<(route: Route) => { ground: LngLat[][]; upper: LngLat[][] }>(floorPartsJs);
export const projectOnRoute = js<(route: Route, coord: LngLat, options?: { from?: number; to?: number; near?: number }) => { along: number; distance: number }>(
  projectOnRouteJs,
);
export const createFixFilter = js<(options?: { start?: LngLat }) => FixFilter>(createFixFilterJs);
export const createPromptPlanner = js<(maneuvers: Maneuver[]) => PromptPlanner>(createPromptPlannerJs);
export const createStepCounter = js<(options?: { threshold?: number }) => ((magnitude: number, timeMs: number) => boolean) & { threshold: number; lastPeak: number | null }>(
  createStepCounterJs,
);
export const meters = js<(a: LngLat, b: LngLat) => number>(metersJs);
export const movePoint = js<(coord: LngLat, heading: number, distance: number) => LngLat>(movePointJs);
export const glidePoint = js<(from: LngLat, to: LngLat, elapsedMs: number) => LngLat>(glidePointJs);
export const drawInFraction = js<(elapsedMs: number) => number>(drawInFractionJs);
export const partialLines = js<(parts: LngLat[][], fraction: number) => LngLat[][]>(partialLinesJs);
export const createSimulator = js<(options: { emit: (fix: Fix) => void; caughtUp?: (coord: LngLat) => boolean }) => Simulator>(createSimulatorJs);

export function createTracker(route: Route, maneuvers: Maneuver[], options: TrackerOptions): Tracker {
  const Constructor = TrackerJs as unknown as new (route: Route, maneuvers: Maneuver[], options: TrackerOptions) => Tracker;
  return new Constructor(route, maneuvers, options);
}

export type Navigation = { graph: Graph; places: Place[]; placeById: Map<string, Place>; data: NavData };

// Built once per map: a new map object only comes when the server sent a changed map. Reroutes add points to the
// graph (they split a walkway where the person is), as on the web.
const prepared = new WeakMap<CampusMap, Navigation>();

/** The walk graph and the places to pick from, for this map. */
export function prepareNavigation(map: CampusMap): Navigation {
  let navigation = prepared.get(map);
  if (!navigation) {
    const graph = buildGraph(map.nav, { warn: () => {} });
    const places = listPlaces(graph, map.nav.paths);
    navigation = { graph, places, placeById: new Map(places.map((place) => [place.id, place])), data: map.nav };
    prepared.set(map, navigation);
  }
  return navigation;
}
