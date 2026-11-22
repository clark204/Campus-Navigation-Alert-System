import { createAudioPlayer, setAudioModeAsync, type AudioPlayer } from 'expo-audio';
import { Vibration } from 'react-native';

// The emergency alarm (looping, with vibration until the person taps) and the warning chime. Same sounds and
// volumes as CNAS-web (public/sounds, resources/js/alerts/alarm.js): drills use the softer drill sound.
const ALARM_VIBRATION = [0, 500, 200, 500, 200, 500, 200, 500, 800];
const DRILL_VIBRATION = [0, 300, 300, 300, 800];

type Players = { alarm: AudioPlayer; drill: AudioPlayer; chime: AudioPlayer };
let players: Players | null = null;
let modeSet = false;

function load(): Players {
  if (!players) {
    players = {
      alarm: createAudioPlayer(require('../../assets/sounds/alarm.wav')),
      drill: createAudioPlayer(require('../../assets/sounds/drill.wav')),
      chime: createAudioPlayer(require('../../assets/sounds/chime.wav')),
    };
    players.alarm.volume = 1;
    players.drill.volume = 0.6;
    players.chime.volume = 0.8;
    players.alarm.loop = true;
    players.drill.loop = true;
  }
  return players;
}

async function ensureMode() {
  if (modeSet) return;
  modeSet = true;
  try {
    // Play even with the iPhone's silent switch on, and pause other audio (music) while the alarm sounds.
    await setAudioModeAsync({ playsInSilentMode: true, interruptionMode: 'doNotMix' });
  } catch {
    modeSet = false;
  }
}

function playFromStart(player: AudioPlayer) {
  player
    .seekTo(0)
    .catch(() => {})
    .finally(() => player.play());
}

export async function startAlarm(isDrill: boolean) {
  stopAlarm();
  await ensureMode();
  const { alarm, drill } = load();
  playFromStart(isDrill ? drill : alarm);
  Vibration.vibrate(isDrill ? DRILL_VIBRATION : ALARM_VIBRATION, true);
}

export function stopAlarm() {
  Vibration.cancel();
  if (!players) return;
  players.alarm.pause();
  players.drill.pause();
}

export async function chime() {
  await ensureMode();
  playFromStart(load().chime);
}
