import { useEffect, useSyncExternalStore } from 'react';

import { api, ApiError, errorMessage } from './api';
import { readJson, removeKeys, writeJson } from './storage';
import type { BellNotification, ClassItem, SavedPlace } from './types';

// The portal data the app keeps for offline reading: the class schedule and the saved places. Each is one shared copy
// for every screen (Home, Schedule, Saved places, the navigation card), saved in AsyncStorage after every load, so the
// last copy shows without internet. Cleared on sign-out (session.tsx).

export type Resource<T> = {
  data: T | null;
  /** When `data` came from the server (ms), for "Saved on …" while offline. */
  savedAt: number | null;
  loading: boolean;
  /** The last load failed; `data` is the saved copy (or null). */
  error: string | null;
};

export type Store<T> = {
  get: () => Resource<T>;
  subscribe: (listener: () => void) => () => void;
  /** Reads the saved copy once, then asks the server. Skipped within 30 s of the last load unless forced. */
  load: (force?: boolean) => Promise<void>;
  /** Replace the data (after an add, edit or delete) and save it. */
  set: (data: T) => void;
  reset: () => void;
  key: string;
};

const FRESH_MS = 30 * 1000;

export function createStore<T>(key: string, fetcher: () => Promise<T>): Store<T> {
  const empty: Resource<T> = { data: null, savedAt: null, loading: false, error: null };
  let state = empty;
  let cacheRead = false;
  let lastLoad = 0;
  let generation = 0;
  const listeners = new Set<() => void>();

  const update = (next: Partial<Resource<T>>) => {
    state = { ...state, ...next };
    listeners.forEach((listener) => listener());
  };

  return {
    key,
    get: () => state,
    subscribe: (listener) => {
      listeners.add(listener);
      return () => listeners.delete(listener);
    },
    async load(force = false) {
      if (state.loading || (!force && Date.now() - lastLoad < FRESH_MS)) return;
      const mine = generation;
      lastLoad = Date.now();
      update({ loading: true });
      if (!cacheRead) {
        cacheRead = true;
        const saved = await readJson<{ data: T; savedAt: number } | null>(key, null);
        if (saved && mine === generation && state.data === null) update({ data: saved.data, savedAt: saved.savedAt });
      }
      try {
        const data = await fetcher();
        if (mine !== generation) return;
        const savedAt = Date.now();
        update({ data, savedAt, loading: false, error: null });
        writeJson(key, { data, savedAt });
      } catch (failure) {
        if (mine !== generation) return;
        // Signed out meanwhile (401): the session clears everything.
        if (failure instanceof ApiError && failure.status === 401) return;
        lastLoad = 0;
        update({ loading: false, error: errorMessage(failure) });
      }
    },
    set(data) {
      const savedAt = Date.now();
      update({ data, savedAt, error: null });
      writeJson(key, { data, savedAt });
    },
    reset() {
      generation++;
      cacheRead = false;
      lastLoad = 0;
      state = empty;
      listeners.forEach((listener) => listener());
    },
  };
}

export type Schedule = { classes: ClassItem[]; term: string | null };
export type Places = { saved: SavedPlace[]; recent: SavedPlace[] };

export const scheduleStore = createStore<Schedule>('cnas.schedule', async () => {
  const result = await api<{ data: ClassItem[]; meta?: { term: string | null } }>('/schedule');
  return { classes: result.data, term: result.meta?.term ?? null };
});

export const placesStore = createStore<Places>('cnas.places', () => api<Places>('/places'));

/** Use a store on screen: loads when the screen mounts (refreshes if it's older than 30 s). */
export function useResource<T>(store: Store<T>): Resource<T> {
  const resource = useSyncExternalStore(store.subscribe, store.get);
  useEffect(() => {
    store.load();
  }, [store]);
  return resource;
}

/** Sign-out: forget the saved copies on this phone. */
export async function clearPortalData() {
  scheduleStore.reset();
  placesStore.reset();
  bellStore.reset();
  await removeKeys([scheduleStore.key, placesStore.key, bellStore.key]);
}

// ---------- Saved places (docs/portal.md): "Save" on the navigation card, like the web's saved-places.js.

/**
 * Save or remove a room at once (the star changes on tap) and undo it when the server refuses or can't be reached.
 * Resolves to the new state; rejects after undoing.
 */
export async function toggleSavedPlace(place: SavedPlace): Promise<boolean> {
  const current = placesStore.get().data ?? { saved: [], recent: [] };
  const wasSaved = current.saved.some((saved) => saved.code === place.code);
  const apply = (saved: SavedPlace[]) => placesStore.set({ ...(placesStore.get().data ?? current), saved });

  apply(wasSaved ? current.saved.filter((saved) => saved.code !== place.code) : [place, ...current.saved]);
  try {
    await api(`/places/${encodeURIComponent(place.code)}/save`, { method: wasSaved ? 'DELETE' : 'POST' });
  } catch (failure) {
    apply(current.saved);
    throw failure;
  }
  // The server's copy has the place's full location label.
  placesStore.load(true);
  return !wasSaved;
}

/** Start on Navigate: the room goes to the top of Recent. Best effort: it never gets in the way of navigating. */
export function recordRecentPlace(code: string) {
  api(`/places/${encodeURIComponent(code)}/recent`, { method: 'POST' })
    .then(() => placesStore.load(true))
    .catch(() => {});
}

/** "Saved on Mon 3:04 PM" for a copy shown offline. */
export function savedAtLabel(savedAt: number | null): string {
  if (!savedAt) return '';
  const date = new Date(savedAt);
  const day = date.toLocaleDateString(undefined, { weekday: 'short', month: 'short', day: 'numeric' });
  const time = date.toLocaleTimeString(undefined, { hour: 'numeric', minute: '2-digit' });
  return `${day}, ${time}`;
}

// ---------- The notification bell (GET /api/notifications): its unread count shows on Home. Not saved offline.

export type Bell = { unread_count: number; data: BellNotification[] };

export const bellStore = createStore<Bell>('cnas.bell', () => api<Bell>('/notifications'));
