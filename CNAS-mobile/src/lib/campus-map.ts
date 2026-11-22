import { useEffect, useSyncExternalStore } from 'react';

import { API_URL } from './config';
import { readJson, writeJson } from './storage';
import type { Alert } from './types';

// GET /api/map (CNAS-web app/Services/CampusMap.php): what the app draws (building outlines and paths) plus what the
// navigation engine needs (`nav`: the GeoJSON sections as the web's /navigate gets them, without photos).
// Kept in AsyncStorage with its ETag, so the map shows offline and is only downloaded again when it changed (304).

type Position = [number, number]; // [longitude, latitude]

export type MapBuilding = {
  id: string;
  name: string;
  type: 'campus' | 'building' | 'facility' | 'evacuation';
  ring: Position[];
};

export type MapPath = { id: string; type: 'walkway' | 'gate'; floor: number; line: Position[] };

type Feature = { type: 'Feature'; properties: Record<string, any>; geometry: { type: string; coordinates: any } };
type FeatureCollection = { type: 'FeatureCollection'; features: Feature[] };

/** The map sections the shared navigation engine reads (CNAS-web resources/js/navigation/graph.js, instructions.js). */
export type NavData = {
  buildings: FeatureCollection;
  paths: FeatureCollection;
  /** The rooms. */
  entrances: FeatureCollection;
  stairs: FeatureCollection;
  landmarks: { buildings: Record<string, string>; paths: Record<string, string> };
};

export type CampusMap = {
  buildings: MapBuilding[];
  paths: MapPath[];
  /** Friendly names by building code ("the Library"). */
  landmarks: Record<string, string>;
  nav: NavData;
};

type Cached = { etag: string | null; map: CampusMap };

// v2: the map now keeps rooms, stairs and path names for navigation; v1 copies are downloaded again.
const CACHE_KEY = 'cnas.map.v2';

/** Same colors as the web map (CNAS-web resources/js/map-style.js). */
export const MAP_COLORS = {
  ground: '#d5e8c3',
  campus: '#a8d08d',
  evacuation: '#5fae5a',
  facility: '#e4d8c6',
  walkway: '#ffffff',
  gate: '#C59B27',
  building: '#b5483a',
  affected: '#B3121B',
  affectedEdge: '#7A0A10',
};

function slim(data: any): CampusMap {
  const buildings: MapBuilding[] = (data?.buildings?.features ?? [])
    .filter((f: any) => f?.geometry?.type === 'Polygon' && Array.isArray(f.geometry.coordinates?.[0]))
    .map((f: any) => ({ id: f.properties.id, name: f.properties.name, type: f.properties.type, ring: f.geometry.coordinates[0] }));
  const paths: MapPath[] = (data?.paths?.features ?? [])
    .filter((f: any) => f?.geometry?.type === 'LineString')
    .map((f: any) => ({ id: f.properties.id, type: f.properties.type, floor: f.properties.floor ?? 1, line: f.geometry.coordinates }));
  return { buildings, paths, landmarks: data?.landmarks?.buildings ?? {}, nav: navData(data) };
}

// Photos are left out: navigation doesn't use them, and they would only make the saved copy bigger.
const collection = (section: any): FeatureCollection => ({
  type: 'FeatureCollection',
  features: (section?.features ?? []).map(({ type, geometry, properties: { photos, ...properties } }: any) => ({ type, geometry, properties })),
});

function navData(data: any): NavData {
  return {
    buildings: collection(data?.buildings),
    paths: collection(data?.paths),
    entrances: collection(data?.entrances),
    stairs: collection(data?.stairs),
    landmarks: { buildings: data?.landmarks?.buildings ?? {}, paths: data?.landmarks?.paths ?? {} },
  };
}

// One shared copy for every screen (mini-maps and the map screen).
type State = { map: CampusMap | null; loading: boolean; failed: boolean };
let state: State = { map: null, loading: false, failed: false };
let etag: string | null = null;
let cacheRead = false;
let lastFetch = 0;
const listeners = new Set<() => void>();

function set(next: Partial<State>) {
  state = { ...state, ...next };
  listeners.forEach((listener) => listener());
}

const REFRESH_MS = 5 * 60 * 1000;

/** Load the cached map, then ask the server whether it changed. Runs at most every 5 minutes unless forced. */
export async function loadCampusMap(force = false) {
  if (state.loading || (!force && Date.now() - lastFetch < REFRESH_MS)) return;
  lastFetch = Date.now();
  set({ loading: true, failed: false });

  if (!cacheRead) {
    cacheRead = true;
    const cached = await readJson<Cached | null>(CACHE_KEY, null);
    if (cached?.map) {
      etag = cached.etag;
      set({ map: cached.map });
    }
  }

  try {
    const headers: Record<string, string> = { Accept: 'application/json' };
    if (etag && state.map) headers['If-None-Match'] = etag;
    const response = await fetch(`${API_URL}/map`, { headers });
    if (response.status === 304) {
      set({ loading: false });
      return;
    }
    if (!response.ok) throw new Error(`HTTP ${response.status}`);
    const body = await response.json();
    const map = slim(body?.data);
    etag = response.headers.get('ETag');
    writeJson(CACHE_KEY, { etag, map } satisfies Cached);
    set({ map, loading: false });
  } catch {
    // Offline: keep the cached copy, and try again the next time a map is opened.
    lastFetch = 0;
    set({ loading: false, failed: true });
  }
}

/** The campus map for drawing: the cached copy right away, refreshed in the background. */
export function useCampusMap(): State & { retry: () => void } {
  const current = useSyncExternalStore(
    (listener) => {
      listeners.add(listener);
      return () => listeners.delete(listener);
    },
    () => state,
  );
  useEffect(() => {
    loadCampusMap();
  }, []);
  return { ...current, retry: () => loadCampusMap(true) };
}

/** "the Library" → "Library": landmark names are written for directions. */
export function buildingLabel(map: CampusMap, building: MapBuilding): string {
  const landmark = map.landmarks[building.id]?.trim();
  if (!landmark) return building.name;
  const name = landmark.replace(/^the\s+/i, '');
  return name.charAt(0).toUpperCase() + name.slice(1);
}

// --- Drawing: a flat projection in meters around the campus, good enough for a few hundred meters. ---

const METERS_PER_DEGREE = 111320;

export type Projection = {
  /** [longitude, latitude] → [x, y] in meters, y pointing down (SVG). */
  project: (position: Position) => [number, number];
  /** The bounding box of the campus, in projected meters. */
  box: { minX: number; minY: number; maxX: number; maxY: number };
  /** The furthest campus point from the box's middle, in meters (fits the campus in a circle). */
  radius: number;
};

export function projectionFor(map: CampusMap): Projection | null {
  const campus = map.buildings.find((b) => b.type === 'campus');
  const outline = campus ? campus.ring : map.buildings.flatMap((b) => b.ring);
  if (outline.length === 0) return null;

  const lons = outline.map((p) => p[0]);
  const lats = outline.map((p) => p[1]);
  const lon0 = (Math.min(...lons) + Math.max(...lons)) / 2;
  const lat0 = (Math.min(...lats) + Math.max(...lats)) / 2;
  const cos = Math.cos((lat0 * Math.PI) / 180);
  const project = ([lon, lat]: Position): [number, number] => [(lon - lon0) * cos * METERS_PER_DEGREE, (lat0 - lat) * METERS_PER_DEGREE];

  const points = outline.map(project);
  const xs = points.map((p) => p[0]);
  const ys = points.map((p) => p[1]);
  const radius = Math.max(...points.map(([x, y]) => Math.hypot(x, y)), 1);
  return { project, box: { minX: Math.min(...xs), minY: Math.min(...ys), maxX: Math.max(...xs), maxY: Math.max(...ys) }, radius };
}

export const svgPoints = (projection: Projection, ring: Position[]) =>
  ring.map((p) => projection.project(p).map((n) => n.toFixed(1)).join(',')).join(' ');

/** The middle of an outline (average of its corners), for labels and incident dots. */
export function centroid(projection: Projection, ring: Position[]): [number, number] {
  const corners = ring.length > 1 && ring[0][0] === ring[ring.length - 1][0] && ring[0][1] === ring[ring.length - 1][1] ? ring.slice(0, -1) : ring;
  const points = corners.map(projection.project);
  return [points.reduce((s, p) => s + p[0], 0) / points.length, points.reduce((s, p) => s + p[1], 0) / points.length];
}

/**
 * Where an active emergency is, in projected meters: its pin, else the middle of its affected buildings. Null when
 * there is none, it isn't an emergency, or it has no location.
 */
export function incidentPoint(map: CampusMap, projection: Projection, alert: Alert | null): { x: number; y: number; radius: number | null } | null {
  if (!alert || alert.status !== 'active' || alert.severity !== 'emergency' || !alert.has_location) return null;
  if (alert.latitude !== null && alert.longitude !== null) {
    const [x, y] = projection.project([alert.longitude, alert.latitude]);
    return { x, y, radius: alert.danger_radius_m };
  }
  const affected = map.buildings.filter((b) => alert.building_codes.includes(b.id));
  if (affected.length === 0) return null;
  const middles = affected.map((b) => centroid(projection, b.ring));
  return { x: middles.reduce((s, p) => s + p[0], 0) / middles.length, y: middles.reduce((s, p) => s + p[1], 0) / middles.length, radius: null };
}

/** Buildings an active emergency names, drawn in alarm red like the web. */
export function affectedCodes(alert: Alert | null): string[] {
  return alert?.status === 'active' && alert.severity === 'emergency' ? alert.building_codes : [];
}
