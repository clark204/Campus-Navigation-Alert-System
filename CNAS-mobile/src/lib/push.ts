import Constants from 'expo-constants';
import * as Device from 'expo-device';
import * as Notifications from 'expo-notifications';
import {
  AndroidAudioContentType,
  AndroidAudioUsage,
  AndroidImportance,
  AndroidNotificationVisibility,
  type NotificationChannelInput,
} from 'expo-notifications';
import { isRunningInExpoGo } from 'expo';
import { Platform } from 'react-native';

import { api } from './api';
import { readJson, writeJson } from './storage';

// Phone push for alerts (CNAS-web docs/alerts.md, "Phone push"). The server sends through Expo with these Android
// channels, so the channel ids and sound files here must match App\Services\ExpoPush.
//
// Nothing exported here rejects: every native or network failure is logged with console.warn and the app carries on
// (the in-app alerts work without push).

/** The Expo push token last sent to the server; /api/logout and "turn off" need it. */
const TOKEN_KEY = 'cnas.push.token';
/** "Turn off alerts" on this phone: don't register again on the next start. */
const OFF_KEY = 'cnas.push.off';
/** The "Turn on emergency alerts" screen was shown once. */
const PROMPTED_KEY = 'cnas.push.prompted';

/** Same pattern as the web takeover and web push (resources/js/alerts/alarm.js), after Android's leading delay. */
const ALARM_VIBRATION = [0, 500, 200, 500, 200, 500, 200, 500];
const DRILL_VIBRATION = [0, 300, 300, 300];

const warn = (what: string, error: unknown) => console.warn(`[CNAS] ${what}:`, error instanceof Error ? error.message : error);

export type PushSupport = 'ok' | 'web' | 'expo-go' | 'simulator';

/** Whether this build can get an Expo push token. Expo Go on Android can't (SDK 53+); the app runs fine without. */
export function pushSupport(): PushSupport {
  if (Platform.OS === 'web') return 'web';
  if (isRunningInExpoGo()) return 'expo-go';
  if (!Device.isDevice) return 'simulator';
  return 'ok';
}

type Channel = { id: string; options: NotificationChannelInput };

const CHANNELS: Channel[] = [
  {
    id: 'emergency',
    options: {
      name: 'Emergency alerts',
      description: 'Fire, earthquake and other emergencies on campus. Loud alarm.',
      importance: AndroidImportance.MAX,
      sound: 'alarm.wav',
      vibrationPattern: ALARM_VIBRATION,
      enableVibrate: true,
      bypassDnd: true,
      lockscreenVisibility: AndroidNotificationVisibility.PUBLIC,
      // The alarm stream, so the alarm plays at alarm volume. Every field the native code reads, flags included.
      audioAttributes: {
        usage: AndroidAudioUsage.ALARM,
        contentType: AndroidAudioContentType.SONIFICATION,
        flags: { enforceAudibility: true, requestHardwareAudioVideoSynchronization: false },
      },
    },
  },
  {
    id: 'drill',
    options: {
      name: 'Drills',
      description: 'Practice alerts. Softer sound.',
      importance: AndroidImportance.HIGH,
      sound: 'drill.wav',
      vibrationPattern: DRILL_VIBRATION,
      enableVibrate: true,
      lockscreenVisibility: AndroidNotificationVisibility.PUBLIC,
    },
  },
  {
    id: 'warning',
    options: {
      name: 'Warnings and updates',
      description: 'Warnings, advisories and "All clear".',
      importance: AndroidImportance.HIGH,
      sound: 'chime.wav',
      lockscreenVisibility: AndroidNotificationVisibility.PUBLIC,
    },
  },
];

/**
 * One channel, with fallbacks so a phone always ends up with it: the full options; then without the audio attributes
 * (custom sound kept); then with the phone's default sound. Each failure is logged. Android keeps a channel's sound
 * once it exists, so a fallback channel stays that way until the app's data is cleared or it is reinstalled.
 */
async function createChannel({ id, options }: Channel): Promise<void> {
  const { audioAttributes, sound, ...rest } = options;
  const attempts: [string, NotificationChannelInput][] = [['full options', options]];
  if (audioAttributes) attempts.push(['without audio attributes', { ...rest, sound }]);
  if (sound) attempts.push(['with the default sound', rest]);

  for (const [index, [label, input]] of attempts.entries()) {
    try {
      await Notifications.setNotificationChannelAsync(id, input);
      if (index > 0) console.warn(`[CNAS] Notification channel "${id}" was created ${label}.`);
      return;
    } catch (error) {
      warn(`Could not create notification channel "${id}" (${label})`, error);
    }
  }
}

/**
 * The Android channels the server's pushes name (created once; Android keeps their settings after that). They must
 * exist before asking for the token on Android 13+. Not in Expo Go: it gets no push, and it has none of the custom
 * sounds (they are only bundled into a development or preview build).
 */
export async function ensureChannels(): Promise<void> {
  if (Platform.OS !== 'android' || pushSupport() === 'expo-go' || pushSupport() === 'web') return;
  for (const channel of CHANNELS) await createChannel(channel);
}

export type Permission = 'granted' | 'denied' | 'undetermined';
type PermissionState = { status: Permission; canAskAgain: boolean };

export async function getPermission(): Promise<PermissionState> {
  if (Platform.OS === 'web') return { status: 'denied', canAskAgain: false };
  try {
    const result = await Notifications.getPermissionsAsync();
    return { status: result.granted ? 'granted' : result.status === 'undetermined' ? 'undetermined' : 'denied', canAskAgain: result.canAskAgain };
  } catch (error) {
    warn('Could not read the notification permission', error);
    return { status: 'denied', canAskAgain: false };
  }
}

/** Android's own "Allow notifications?" question. In Expo Go only the question, nothing else. */
export async function requestPermission(): Promise<PermissionState> {
  if (Platform.OS === 'web') return { status: 'denied', canAskAgain: false };
  try {
    await ensureChannels();
    const result = await Notifications.requestPermissionsAsync();
    return { status: result.granted ? 'granted' : 'denied', canAskAgain: result.canAskAgain };
  } catch (error) {
    warn('Could not ask for the notification permission', error);
    return getPermission();
  }
}

/** The Expo push token for this install, or null when this build can't have one or it failed (logged, never thrown). */
async function expoToken(): Promise<string | null> {
  const support = pushSupport();
  if (support !== 'ok') {
    if (support === 'expo-go') console.info('[CNAS] Push alerts are skipped in Expo Go. Use the development build to test them.');
    if (support === 'simulator') console.info('[CNAS] Push alerts need a real phone, not an emulator.');
    return null;
  }

  const projectId = Constants.expoConfig?.extra?.eas?.projectId ?? Constants.easConfig?.projectId;
  if (!projectId) {
    console.warn('[CNAS] No EAS projectId in app.json (extra.eas.projectId). Run `npx eas-cli@latest init`; push alerts are off until then.');
    return null;
  }

  try {
    await ensureChannels();
    return (await Notifications.getExpoPushTokenAsync({ projectId })).data;
  } catch (error) {
    warn('Could not get the Expo push token (is google-services.json in the build?)', error);
    return null;
  }
}

/**
 * Send this phone's token to CNAS (POST /api/push-tokens). Signed in, the api helper adds the bearer token, so the
 * server links the phone to the account. Does nothing when alerts are off, not allowed, or not possible here. Returns
 * the token, or null (failures are logged; the next app start tries again).
 */
export async function registerForAlerts(): Promise<string | null> {
  try {
    if (await alertsTurnedOff()) {
      await retryPendingDelete();
      return null;
    }
    if ((await getPermission()).status !== 'granted') return null;

    const token = await expoToken();
    if (!token) return null;

    await api('/push-tokens', {
      method: 'POST',
      body: { expo_token: token, platform: Platform.OS === 'ios' ? 'ios' : 'android', device_name: deviceLabel() },
    });
    await writeJson(TOKEN_KEY, token);
    return token;
  } catch (error) {
    warn('Could not register this phone for push alerts', error);
    return null;
  }
}

/**
 * "Turn off alerts": the server forgets this phone (DELETE /api/push-tokens) and it won't register again. Offline, the
 * token is kept and the delete is tried again on the next start.
 */
export async function turnOffAlerts(): Promise<void> {
  await writeJson(OFF_KEY, true);
  await retryPendingDelete();
}

async function retryPendingDelete(): Promise<void> {
  const token = await storedPushToken();
  if (!token) return;
  try {
    await api('/push-tokens', { method: 'DELETE', body: { expo_token: token } });
    await writeJson(TOKEN_KEY, null);
  } catch (error) {
    warn('Could not turn off push alerts on the server (will try again on the next start)', error);
  }
}

/** "Turn on alerts" again after turning them off. */
export async function turnOnAlerts(): Promise<string | null> {
  await writeJson(OFF_KEY, false);
  return registerForAlerts();
}

export const alertsTurnedOff = () => readJson<boolean>(OFF_KEY, false);
export const storedPushToken = () => readJson<string | null>(TOKEN_KEY, null);
export const wasPrompted = () => readJson<boolean>(PROMPTED_KEY, false);
export const markPrompted = () => writeJson(PROMPTED_KEY, true);

function deviceLabel(): string {
  return [Device.manufacturer, Device.modelName].filter(Boolean).join(' ').trim() || 'Phone';
}

/** The alert a notification is about, from the data the server sends ({alert_id, screen}). */
export function alertIdOf(notification: Notifications.Notification): number | null {
  const id = Number(notification.request.content.data?.alert_id);
  return Number.isFinite(id) && id > 0 ? id : null;
}
