import { createContext, useCallback, useContext, useEffect, useMemo, useRef, useState, type ReactNode } from 'react';
import { AppState } from 'react-native';

import { chime, startAlarm, stopAlarm } from './alarm';
import { bannerAlert, msUntilExpiry, remember, seenKey, shouldPopUp, shouldTakeOver } from './alert-rules';
import { api } from './api';
import { MARKED_SAFE_KEY, useSession } from './session';
import { readJson, writeJson } from './storage';
import type { Alert } from './types';

// Like the web pages: GET /api/alerts/active every 15 seconds, only while the app is on screen.
const POLL_MS = 15000;
const SEEN_KEY = 'cnas.alerts.seen';
const DISMISSED_KEY = 'cnas.alerts.dismissed';

export const SAFE_THANKS = 'Thanks. The administrators can see that you are safe.';

type Alerts = {
  /** What the banner shows: the active alert, or a recent "All clear"/"False alarm". */
  banner: Alert | null;
  /** The full-screen emergency takeover, or null. */
  takeover: Alert | null;
  /** The one-time warning popup, or null. */
  popup: Alert | null;
  /** The takeover's alarm is sounding (stops on the first tap). */
  sounding: boolean;
  silence: () => void;
  dismissTakeover: () => void;
  dismissPopup: () => void;
  /** Close the green banner of a resolved alert on this phone. */
  dismissBanner: (alert: Alert) => void;
  isMarkedSafe: (alert: Alert) => boolean;
  /** POST /api/alerts/{id}/safe. Throws ApiError with the message to show (409 when the alert is over). */
  markSafe: (alert: Alert) => Promise<void>;
  /** Ask the server now instead of waiting for the next 15 s poll (a push arrived). */
  check: () => Promise<void>;
  /**
   * A tapped notification: check now and show that alert again even if this phone already showed it (the takeover
   * for an emergency, the popup for a warning).
   */
  showAlert: (alertId: number) => void;
};

type MarkedSafe = { userId: number | null; ids: number[] };

const AlertsContext = createContext<Alerts | null>(null);

export function AlertsProvider({ children }: { children: ReactNode }) {
  const [alert, setAlert] = useState<Alert | null>(null);
  const [loaded, setLoaded] = useState(false);
  const [seen, setSeen] = useState<string[]>([]);
  const [dismissed, setDismissed] = useState<number[]>([]);
  // Alert ids this user marked safe on this phone, with whose answers they are.
  const [markedSafe, setMarkedSafe] = useState<MarkedSafe>({ userId: null, ids: [] });
  const [silencedVersion, setSilencedVersion] = useState<string | null>(null);
  // An alert whose notification was tapped: shown again even if already seen, until dismissed.
  const [reopenId, setReopenId] = useState<number | null>(null);
  const [now, setNow] = useState(() => Date.now());

  useEffect(() => {
    (async () => {
      setSeen(await readJson<string[]>(SEEN_KEY, []));
      setDismissed(await readJson<number[]>(DISMISSED_KEY, []));
      setMarkedSafe(await readJson<MarkedSafe>(MARKED_SAFE_KEY, { userId: null, ids: [] }));
      setLoaded(true);
    })();
  }, []);

  const { user } = useSession();
  const userId = user?.id ?? null;

  const check = useCallback(async () => {
    try {
      const { data } = await api<{ data: Alert | null }>('/alerts/active');
      setAlert(data);
      setNow(Date.now());
    } catch {
      // Offline or a server error: keep showing what we had, try again on the next tick.
    }
  }, []);

  // Poll while in the foreground; stop in the background; check right away when the app comes back.
  const timer = useRef<ReturnType<typeof setInterval> | null>(null);
  useEffect(() => {
    const start = () => {
      if (timer.current) return;
      check();
      timer.current = setInterval(check, POLL_MS);
    };
    const stop = () => {
      if (timer.current) clearInterval(timer.current);
      timer.current = null;
    };

    if (AppState.currentState === 'active') start();
    const subscription = AppState.addEventListener('change', (state) => (state === 'active' ? start() : stop()));
    return () => {
      subscription.remove();
      stop();
    };
  }, [check]);

  // Re-render when a green banner's time is up.
  useEffect(() => {
    const ms = msUntilExpiry(alert, now);
    if (ms === null || ms === 0) return;
    const timeout = setTimeout(() => setNow(Date.now()), ms + 500);
    return () => clearTimeout(timeout);
  }, [alert, now]);

  // A tapped notification shows its alert again: as if this phone had not seen it yet.
  const seenNow = alert && reopenId === alert.id ? seen.filter((key) => key !== seenKey(alert)) : seen;
  const takeover = loaded && shouldTakeOver(alert, seenNow) ? alert : null;
  const popup = loaded && !takeover && shouldPopUp(alert, seenNow) ? alert : null;
  const sounding = takeover !== null && silencedVersion !== takeover.version;
  const soundingDrill = sounding && takeover.is_drill;

  useEffect(() => {
    if (!sounding) {
      stopAlarm();
      return;
    }
    startAlarm(soundingDrill);
    return () => stopAlarm();
  }, [sounding, soundingDrill]);

  const popupVersion = popup?.version ?? null;
  useEffect(() => {
    if (popupVersion) chime();
  }, [popupVersion]);

  const markSeen = useCallback((shown: Alert) => {
    setSeen((list) => {
      const next = remember(list, seenKey(shown));
      writeJson(SEEN_KEY, next);
      return next;
    });
  }, []);

  const silence = useCallback(() => {
    if (takeover) setSilencedVersion(takeover.version);
  }, [takeover]);

  const dismissTakeover = useCallback(() => {
    if (takeover) markSeen(takeover);
    setReopenId(null);
  }, [takeover, markSeen]);

  const dismissPopup = useCallback(() => {
    if (popup) markSeen(popup);
    setReopenId(null);
  }, [popup, markSeen]);

  const showAlert = useCallback(
    (alertId: number) => {
      setReopenId(alertId);
      setSilencedVersion(null);
      check();
    },
    [check],
  );

  const dismissBanner = useCallback((closed: Alert) => {
    setDismissed((list) => {
      const next = remember(list, closed.id);
      writeJson(DISMISSED_KEY, next);
      return next;
    });
  }, []);

  const markSafe = useCallback(
    async (target: Alert) => {
      try {
        await api(`/alerts/${target.id}/safe`, { method: 'POST' });
      } catch (error) {
        // 409: the alert ended or changed level; refresh so the screen shows that.
        check();
        throw error;
      }
      setMarkedSafe((current) => {
        const next = { userId, ids: remember(current.userId === userId ? current.ids : [], target.id) };
        writeJson(MARKED_SAFE_KEY, next);
        return next;
      });
    },
    [check, userId],
  );

  // Answered on this phone, or (my_safety_status from the server) on the website or another phone.
  const isMarkedSafe = useCallback(
    (target: Alert) =>
      userId !== null &&
      ((markedSafe.userId === userId && markedSafe.ids.includes(target.id)) || (alert?.id === target.id && alert.my_safety_status === 'safe')),
    [markedSafe, userId, alert],
  );

  const value = useMemo<Alerts>(
    () => ({
      banner: bannerAlert(alert, dismissed, now),
      takeover,
      popup,
      sounding,
      silence,
      dismissTakeover,
      dismissPopup,
      dismissBanner,
      isMarkedSafe,
      markSafe,
      check,
      showAlert,
    }),
    [alert, dismissed, now, takeover, popup, sounding, silence, dismissTakeover, dismissPopup, dismissBanner, isMarkedSafe, markSafe, check, showAlert],
  );

  return <AlertsContext.Provider value={value}>{children}</AlertsContext.Provider>;
}

export function useAlerts(): Alerts {
  const alerts = useContext(AlertsContext);
  if (!alerts) throw new Error('useAlerts must be used inside <AlertsProvider>');
  return alerts;
}
