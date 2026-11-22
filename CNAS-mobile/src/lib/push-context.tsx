import * as Notifications from 'expo-notifications';
import { createContext, useCallback, useContext, useEffect, useMemo, useState, type ReactNode } from 'react';
import { AppState, Linking, Platform } from 'react-native';

import { useAlerts } from './alerts';
import {
  alertIdOf,
  alertsTurnedOff,
  ensureChannels,
  getPermission,
  markPrompted,
  pushSupport,
  registerForAlerts,
  requestPermission,
  turnOffAlerts,
  turnOnAlerts,
  wasPrompted,
  type Permission,
} from './push';
import { useSession } from './session';

// In the foreground the app's own takeover, popup and banner show the alert (and sound the alarm), so an alert push
// arriving then is only listed, not shown as a second banner with a second sound.
if (Platform.OS !== 'web') {
  Notifications.setNotificationHandler({
    handleNotification: async (notification) => {
      const isAlert = alertIdOf(notification) !== null;
      return { shouldShowBanner: !isAlert, shouldShowList: true, shouldPlaySound: !isAlert, shouldSetBadge: false };
    },
  });
}

type Push = {
  /** null until read at start-up. */
  permission: { status: Permission; canAskAgain: boolean } | null;
  /** The user turned alerts off on this phone. */
  off: boolean;
  /** Show the "Turn on emergency alerts" screen (once, after the intro). */
  needsPrompt: boolean;
  /** Ask for permission (or open the phone's settings when Android won't ask again) and register. */
  turnOn: () => Promise<void>;
  /** DELETE the token on the server; this phone stops getting alert pushes. */
  turnOff: () => Promise<void>;
  /** "Not now" on the prompt. */
  dismissPrompt: () => void;
};

const PushContext = createContext<Push | null>(null);

export function PushProvider({ children }: { children: ReactNode }) {
  const { ready, user } = useSession();
  const { check, showAlert } = useAlerts();
  const [permission, setPermission] = useState<Push['permission']>(null);
  const [off, setOff] = useState(false);
  const [prompted, setPrompted] = useState<boolean | null>(null);
  const userId = user?.id ?? null;

  const refreshPermission = useCallback(async () => {
    try {
      setPermission(await getPermission());
    } catch {
      setPermission({ status: 'denied', canAskAgain: false });
    }
  }, []);

  useEffect(() => {
    if (Platform.OS === 'web') return;
    (async () => {
      await ensureChannels();
      setOff(await alertsTurnedOff());
      setPrompted(await wasPrompted());
      await refreshPermission();
    })();
  }, [refreshPermission]);

  // Register on every start and whenever the account changes: signed in, the server links this phone to the account.
  const granted = permission?.status === 'granted';
  useEffect(() => {
    if (!ready || !granted || off) return;
    registerForAlerts();
  }, [ready, granted, off, userId]);

  // Back from the phone's settings (alerts allowed there): check the permission again.
  useEffect(() => {
    const subscription = AppState.addEventListener('change', (state) => {
      if (state === 'active' && Platform.OS !== 'web') refreshPermission();
    });
    return () => subscription.remove();
  }, [refreshPermission]);

  // A push while the app is open: check at once instead of waiting for the next poll. A tapped notification (also one
  // that started the app): show that alert right away, the takeover for an emergency.
  useEffect(() => {
    if (Platform.OS === 'web') return;
    const open = (response: Notifications.NotificationResponse | null) => {
      if (!response || response.actionIdentifier !== Notifications.DEFAULT_ACTION_IDENTIFIER) return;
      const alertId = alertIdOf(response.notification);
      if (alertId !== null) showAlert(alertId);
      Notifications.clearLastNotificationResponseAsync().catch(() => {});
    };

    Notifications.getLastNotificationResponseAsync().then(open).catch(() => {});
    const received = Notifications.addNotificationReceivedListener((notification) => {
      if (alertIdOf(notification) !== null) check();
    });
    const tapped = Notifications.addNotificationResponseReceivedListener(open);
    return () => {
      received.remove();
      tapped.remove();
    };
  }, [check, showAlert]);

  const turnOn = useCallback(async () => {
    let current = permission ?? (await getPermission());
    if (current.status !== 'granted') {
      if (!current.canAskAgain) {
        // Android only asks twice; after that the switch is in the phone's settings.
        await Linking.openSettings().catch((error) => console.warn('[CNAS] Could not open the settings:', error?.message ?? error));
        return;
      }
      current = await requestPermission();
      setPermission(current);
    }
    setOff(false);
    if (current.status === 'granted') {
      await turnOnAlerts();
    }
  }, [permission]);

  const turnOff = useCallback(async () => {
    setOff(true);
    await turnOffAlerts();
  }, []);

  const dismissPrompt = useCallback(() => {
    setPrompted(true);
    markPrompted();
  }, []);

  const value = useMemo<Push>(
    () => ({
      permission,
      off,
      needsPrompt: pushSupport() !== 'web' && prompted === false && permission?.status === 'undetermined',
      turnOn: async () => {
        dismissPrompt();
        await turnOn();
      },
      turnOff,
      dismissPrompt,
    }),
    [permission, off, prompted, turnOn, turnOff, dismissPrompt],
  );

  return <PushContext.Provider value={value}>{children}</PushContext.Provider>;
}

export function usePush(): Push {
  const push = useContext(PushContext);
  if (!push) throw new Error('usePush must be used inside <PushProvider>');
  return push;
}
