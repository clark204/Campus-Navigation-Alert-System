import * as Speech from 'expo-speech';

import { readJson, removeKeys, writeJson } from '../storage';
import { VOICE_DIRECTIONS_DEFAULT } from './engine';

// "Voice directions" (Account tab). Off by default, like the web (CNAS-web navigation/constants.js).
const VOICE_KEY = 'cnas.nav.voice';
// The old setting: a remembered mute, so voice was on unless muted. Removed, so existing installs start with voice off.
const OLD_MUTED_KEY = 'cnas.nav.muted';

/**
 * Spoken directions, like the web's Voice (CNAS-web navigation/voice.js) on expo-speech: the newest prompt is the
 * relevant one, so anything still being said is dropped first. No unlock tap is needed in the app.
 * - `enabled`: the "Voice directions" setting, saved on the phone.
 * - `muted`: this trip only. Each trip starts from the setting (startTrip); the mute button changes just that trip.
 */
class Voice {
  enabled = VOICE_DIRECTIONS_DEFAULT;
  muted = !VOICE_DIRECTIONS_DEFAULT;
  private listeners = new Set<() => void>();

  constructor() {
    removeKeys([OLD_MUTED_KEY]);
    readJson<boolean>(VOICE_KEY, VOICE_DIRECTIONS_DEFAULT).then((enabled) => {
      this.enabled = enabled === true;
      this.notify();
    });
  }

  subscribe(listener: () => void) {
    this.listeners.add(listener);
    return () => this.listeners.delete(listener);
  }

  /** The Account tab switch. The next trip uses it; a trip under way keeps its own mute. */
  setEnabled(enabled: boolean) {
    this.enabled = enabled;
    writeJson(VOICE_KEY, enabled);
    this.notify();
  }

  /**
   * A new trip: speak if the setting is on. `alwaysOn` is for the route to safety during an emergency (the next step,
   * `destination.kind === 'evacuation'`): it must speak even with voice directions off, like the web's evacuation.
   */
  startTrip({ alwaysOn = false }: { alwaysOn?: boolean } = {}) {
    this.muted = alwaysOn ? false : !this.enabled;
    this.notify();
  }

  /** The mute button while walking: this trip only. */
  setMuted(muted: boolean) {
    this.muted = muted;
    if (muted) Speech.stop();
    this.notify();
  }

  say(text: string | null | undefined) {
    if (!text || this.muted) return;
    Speech.stop();
    Speech.speak(text, { language: 'en-US' });
  }

  silence() {
    Speech.stop();
  }

  private notify() {
    this.listeners.forEach((listener) => listener());
  }
}

export const voice = new Voice();
