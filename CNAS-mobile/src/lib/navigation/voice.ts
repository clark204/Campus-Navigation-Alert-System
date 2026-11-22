import * as Speech from 'expo-speech';

import { readJson, writeJson } from '../storage';

const MUTED_KEY = 'cnas.nav.muted';

/**
 * Spoken directions, like the web's Voice (CNAS-web navigation/voice.js) on expo-speech: the newest prompt is the
 * relevant one, so anything still being said is dropped first. Mute is remembered. No unlock tap is needed in the app.
 */
class Voice {
  muted = false;
  private listeners = new Set<() => void>();

  constructor() {
    readJson<boolean>(MUTED_KEY, false).then((muted) => {
      this.muted = muted;
      this.listeners.forEach((listener) => listener());
    });
  }

  subscribe(listener: () => void) {
    this.listeners.add(listener);
    return () => this.listeners.delete(listener);
  }

  setMuted(muted: boolean) {
    this.muted = muted;
    writeJson(MUTED_KEY, muted);
    if (muted) Speech.stop();
    this.listeners.forEach((listener) => listener());
  }

  say(text: string | null | undefined) {
    if (!text || this.muted) return;
    Speech.stop();
    Speech.speak(text, { language: 'en-US' });
  }

  silence() {
    Speech.stop();
  }
}

export const voice = new Voice();
