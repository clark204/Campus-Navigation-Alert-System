import { setVoiceDirections, voiceDirectionsOn } from './navigation/storage.js';

// The "Voice directions" switch (partials/voice-directions): portal Settings → Navigation (loaded from app.js) and the
// /navigate planning panel for guests and admins (loaded from navigation/main.js, which has no app.js). The setting
// lives in this browser's localStorage, off by default; a trip reads it when it starts (Voice.startTrip).
export function initVoiceDirections(container) {
    const input = container.querySelector('[data-voice-directions-input]');
    input.checked = voiceDirectionsOn();
    input.addEventListener('change', () => setVoiceDirections(input.checked));
    container.hidden = false;
}
