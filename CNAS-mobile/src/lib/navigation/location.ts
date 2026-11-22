import * as Location from 'expo-location';
import { Platform } from 'react-native';

import type { Fix } from './engine';

export type LocationStatus = 'waiting' | 'on' | 'denied' | 'off' | 'error';

/**
 * expo-location's reading in the shape of a browser Geolocation fix, which the shared GPS filter and tracker read
 * (CNAS-web navigation/gps.js). On Android, expo-location reports a missing speed or direction as 0 (it copies
 * Location.getSpeed() and getBearing() without checking hasSpeed() or hasBearing()). Taken as real, every fix would
 * look like standing still facing north, and the dot would freeze. So 0 counts as unknown: the filter then tells
 * walking from standing by steps or distance moved, and the direction from movement, as on the web on phones that
 * give no speed. A missing accuracy counts as too inaccurate.
 */
export function toFix(location: Location.LocationObject): Fix {
  const { latitude, longitude, accuracy, speed, heading } = location.coords;
  const android = Platform.OS === 'android';
  return {
    coords: {
      latitude,
      longitude,
      accuracy: accuracy ?? Infinity,
      speed: speed === null || (android && speed === 0) ? null : speed,
      heading: heading === null || (android && heading === 0) ? null : heading,
    },
    timestamp: location.timestamp,
  };
}

/**
 * Asks for location (while the app is open; no background location) and sends every fix to `onFix`, about once a
 * second. Resolves with how it went and a stop function.
 */
export async function watchLocation(onFix: (fix: Fix) => void, onError: (message: string) => void): Promise<{ status: LocationStatus; stop: () => void }> {
  const none = () => {};
  try {
    const permission = await Location.requestForegroundPermissionsAsync();
    if (permission.status !== 'granted') return { status: 'denied', stop: none };
    if (!(await Location.hasServicesEnabledAsync())) return { status: 'off', stop: none };
    const subscription = await Location.watchPositionAsync(
      { accuracy: Location.Accuracy.BestForNavigation, timeInterval: 1000, distanceInterval: 0 },
      (location) => onFix(toFix(location)),
      onError,
    );
    return { status: 'on', stop: () => subscription.remove() };
  } catch {
    return { status: 'error', stop: none };
  }
}
