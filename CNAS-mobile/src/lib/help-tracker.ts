import * as Location from 'expo-location';
import { useEffect, useRef } from 'react';

import { api, ApiError } from './api';
import { watchLocation } from './navigation/location';
import type { HelpState } from './types';

/** Same as the web (CNAS-web resources/js/alerts/help-tracker.js): a check-in every 15 s while the request is open. */
export const PING_MS = 15000;
const FIRST_FIX_TIMEOUT_MS = 8000;

export type HelpFix = { latitude: number; longitude: number; accuracy: number | null };

/**
 * One position to send with "I need help", or null when location is refused, off or too slow (the request goes
 * anyway). `denied` tells the person why there is no dot on the admins' map.
 */
export async function firstHelpFix(): Promise<{ fix: HelpFix | null; denied: boolean }> {
  try {
    const permission = await Location.requestForegroundPermissionsAsync();
    if (permission.status !== 'granted') return { fix: null, denied: true };
    const reading = await Promise.race([
      Location.getCurrentPositionAsync({ accuracy: Location.Accuracy.High }),
      new Promise<null>((resolve) => setTimeout(() => resolve(null), FIRST_FIX_TIMEOUT_MS)),
    ]);
    if (!reading) return { fix: null, denied: false };
    const { latitude, longitude, accuracy } = reading.coords;
    return { fix: { latitude, longitude, accuracy: accuracy === null ? null : Math.round(accuracy) }, denied: false };
  } catch {
    return { fix: null, denied: false };
  }
}

/**
 * While `open` (this user's "I need help" on an active emergency, not yet helped): watch the position when `share`
 * (only while the app is open; no background location) and post it to /api/alerts/{id}/help/ping every PING_MS
 * (HelpPingController). Each answer goes to `onState`; a 409 (helped, "I'm safe", alert over) calls `onStopped`.
 */
export function useHelpTracker({
  alertId,
  open,
  share,
  onState,
  onStopped,
}: {
  alertId: number | null;
  open: boolean;
  share: boolean;
  onState: (state: HelpState | null) => void;
  onStopped: () => void;
}) {
  // The latest callbacks without restarting the tracker each render.
  const callbacks = useRef({ onState, onStopped });
  useEffect(() => {
    callbacks.current = { onState, onStopped };
  });

  useEffect(() => {
    if (!open || alertId === null) return;
    let cancelled = false;
    let fix: HelpFix | null = null;
    let stopWatching = () => {};

    if (share) {
      watchLocation(
        ({ coords }) => {
          fix = { latitude: coords.latitude, longitude: coords.longitude, accuracy: Number.isFinite(coords.accuracy) ? Math.round(coords.accuracy) : null };
        },
        () => {},
      ).then(({ stop }) => {
        if (cancelled) stop();
        else stopWatching = stop;
      });
    }

    const ping = async () => {
      try {
        const result = await api<{ help: HelpState | null }>(`/alerts/${alertId}/help/ping`, { method: 'POST', body: share && fix ? fix : {} });
        if (!cancelled) callbacks.current.onState(result.help);
      } catch (error) {
        if (!cancelled && error instanceof ApiError && error.status === 409) callbacks.current.onStopped();
        // Offline for a moment: try again at the next ping.
      }
    };
    const timer = setInterval(ping, PING_MS);

    return () => {
      cancelled = true;
      clearInterval(timer);
      stopWatching();
    };
  }, [alertId, open, share]);
}
