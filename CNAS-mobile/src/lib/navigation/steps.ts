import { Accelerometer } from 'expo-sensors';
import { Platform } from 'react-native';

import { createStepCounter, DEFAULT_THRESHOLD } from './engine';

// The web's step detector (CNAS-web navigation/steps.js StepDetector) on the phone's accelerometer. The peak
// detector is the shared one; it wants m/s² with gravity included, and expo-sensors reports g.
const G = 9.80665;
// About the rate browsers send devicemotion, which the detector's smoothing was tuned for.
const INTERVAL_MS = 16;

export type StepSensor = { readonly receiving: boolean; readonly lastPeak: number | null; stop: () => void };

/** Calls onStep for each detected step. Steps only help between GPS fixes; without the sensor nothing else changes. */
export function startStepSensor(onStep: () => void): StepSensor {
  const counter = createStepCounter({ threshold: DEFAULT_THRESHOLD });
  let receiving = false;
  let subscription: { remove: () => void } | null = null;
  let stopped = false;

  Accelerometer.isAvailableAsync()
    .then((available) => {
      if (!available || stopped) return;
      // Browsers (the web preview) send motion at their own rate.
      if (Platform.OS !== 'web') Accelerometer.setUpdateInterval(INTERVAL_MS);
      subscription = Accelerometer.addListener(({ x, y, z, timestamp }) => {
        receiving = true;
        const time = Number.isFinite(timestamp) ? timestamp * 1000 : Date.now();
        if (counter(Math.hypot(x, y, z) * G, time)) onStep();
      });
    })
    .catch(() => {});

  return {
    get receiving() {
      return receiving;
    },
    get lastPeak() {
      return counter.lastPeak;
    },
    stop() {
      stopped = true;
      subscription?.remove();
      subscription = null;
    },
  };
}
