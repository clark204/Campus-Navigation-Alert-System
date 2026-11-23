// The help requests on the campus map (Admin > Alerts during an emergency, and an alert's report page). The incident
// pin and danger zone as on the send form (alert-picker.js), and a dot per person who asked for help: red and pulsing
// while they wait, amber once an admin is on the way, green when helped (report only), faded when their position is
// over a minute old. A faint circle shows how accurate the position is.
// Live (data-help-live on the queue): alert-safety-live.js swaps the queue every 5 s and this moves the dots, fills in
// "In Science Building, 40 m from the incident" on each card, and for a new request beeps (when sound is on), flashes
// its card and puts "(1) need help" in the tab title. "Show on map" flies to a person; a dot scrolls to their card.
import maplibregl from 'maplibre-gl';
import 'maplibre-gl/dist/maplibre-gl.css';
import { loadMapData } from '../map-data.js';
import { COLORS } from '../map-style.js';
import { circlePolygon } from '../navigation/evacuation.js';
import { AFFECTED_TYPES } from '../navigation/incident.js';
import { describeWhere, hasPosition, newRequestIds, readHelpPoints, titleWithCount } from './help-data.js';

const ALARM_COLOR = '#B3121B';
const SAFE_COLOR = '#0F6B3A';
const SOUND_KEY = 'cnas.helpSound';
const DOT_COLORS = { new: ALARM_COLOR, responding: '#F59E0B', helped: SAFE_COLOR };
const EMPTY = { type: 'FeatureCollection', features: [] };

function ringBounds(ring) {
    const lngs = ring.map(([lng]) => lng);
    const lats = ring.map(([, lat]) => lat);
    return [[Math.min(...lngs), Math.min(...lats)], [Math.max(...lngs), Math.max(...lats)]];
}

function readStoredSound() {
    try {
        return localStorage.getItem(SOUND_KEY) === 'on';
    } catch {
        return false;
    }
}

function storeSound(on) {
    try {
        localStorage.setItem(SOUND_KEY, on ? 'on' : 'off');
    } catch {
        // Private window: sound stays on for this page only.
    }
}

// Two short rising tones: noticeable, not the alarm.
function beep(audio) {
    if (!audio) return;
    audio.resume?.();
    [660, 880].forEach((frequency, index) => {
        const start = audio.currentTime + index * 0.18;
        const oscillator = audio.createOscillator();
        const gain = audio.createGain();
        oscillator.type = 'sine';
        oscillator.frequency.value = frequency;
        gain.gain.setValueAtTime(0.0001, start);
        gain.gain.exponentialRampToValueAtTime(0.25, start + 0.02);
        gain.gain.exponentialRampToValueAtTime(0.0001, start + 0.16);
        oscillator.connect(gain).connect(audio.destination);
        oscillator.start(start);
        oscillator.stop(start + 0.17);
    });
}

function dotElement(request) {
    const element = document.createElement('button');
    element.type = 'button';
    element.className = 'group/dot flex flex-col items-center gap-0.5 focus-visible:outline-none';
    element.innerHTML = `
        <span class="relative grid size-6 place-items-center">
            <span data-dot-pulse class="dot-pulse absolute inset-0 rounded-full"></span>
            <span data-dot-core class="relative size-[18px] rounded-full border-[3px] border-white shadow-[0_0_0_1.5px_rgba(28,25,23,0.7),0_2px_6px_rgba(0,0,0,0.35)] group-focus-visible/dot:ring-2 group-focus-visible/dot:ring-[#C59B27]"></span>
        </span>
        <span data-dot-label class="max-w-28 truncate rounded-full bg-white/95 px-1.5 py-px text-[11px] font-semibold text-stone-900 shadow"></span>`;
    updateDot(element, request);
    return element;
}

function updateDot(element, request) {
    const color = DOT_COLORS[request.status] ?? ALARM_COLOR;
    element.setAttribute('aria-label', `${request.name}: ${request.status_label}. Show their card.`);
    element.style.opacity = request.stale && request.status !== 'helped' ? '0.7' : '1';
    element.querySelector('[data-dot-core]').style.backgroundColor = color;
    const pulse = element.querySelector('[data-dot-pulse]');
    pulse.style.backgroundColor = color;
    pulse.hidden = request.status !== 'new' || request.stale;
    element.querySelector('[data-dot-label]').textContent = request.name.split(' ')[0];
}

export async function initHelpMap(workspace) {
    const $ = (selector) => workspace.querySelector(selector);
    const container = $('[data-help-map]');
    const live = $('[data-help-live]');
    const pointsRoot = live ?? workspace;
    const status = $('[data-help-status]');
    const soundButton = $('[data-help-sound]');
    const incident = workspace.dataset.incidentLng ? [Number(workspace.dataset.incidentLng), Number(workspace.dataset.incidentLat)] : null;
    const radius = Number(workspace.dataset.radius) || null;
    const affected = JSON.parse(workspace.dataset.affected || '[]');
    const baseTitle = document.title;

    // ---------- Sound (browsers only play after a click on the page, so it starts with the toggle)
    let audio = null;
    let soundOn = Boolean(live) && readStoredSound();
    const ensureAudio = () => {
        if (!audio && (window.AudioContext || window.webkitAudioContext)) audio = new (window.AudioContext || window.webkitAudioContext)();
        return audio;
    };
    const showSound = () => {
        if (!soundButton) return;
        soundButton.setAttribute('aria-pressed', String(soundOn));
        soundButton.querySelector('[data-sound-label]').textContent = soundOn ? 'Sound on' : 'Sound off';
    };
    soundButton?.addEventListener('click', () => {
        soundOn = !soundOn;
        storeSound(soundOn);
        showSound();
        if (soundOn) beep(ensureAudio());
    });
    // A stored "on" needs one click anywhere before the browser lets it play.
    if (soundOn) document.addEventListener('pointerdown', ensureAudio, { once: true });
    showSound();

    // ---------- Map
    let map = null;
    let buildings = [];
    const markers = new Map();
    try {
        const data = await loadMapData(workspace.dataset.mapUrl);
        buildings = data.buildings.features
            .filter((f) => AFFECTED_TYPES.includes(f.properties.type))
            .map((f) => ({ id: f.properties.id, name: f.properties.name, type: f.properties.type, ring: f.geometry.coordinates[0] }));
        const campus = data.buildings.features.find((f) => f.properties.type === 'campus');
        const campusBounds = ringBounds(campus.geometry.coordinates[0]);
        const affectedFilter = ['in', ['get', 'id'], ['literal', affected]];

        map = new maplibregl.Map({
            container,
            style: {
                version: 8,
                sources: {
                    buildings: { type: 'geojson', data: data.buildings },
                    danger: { type: 'geojson', data: incident && radius ? circlePolygon(incident, radius) : EMPTY },
                    accuracy: { type: 'geojson', data: EMPTY },
                },
                layers: [
                    { id: 'ground', type: 'background', paint: { 'background-color': COLORS.ground } },
                    { id: 'campus', type: 'fill', source: 'buildings', filter: ['==', ['get', 'type'], 'campus'], paint: { 'fill-color': COLORS.campus, 'fill-outline-color': '#7e9c6a' } },
                    { id: 'evacuation', type: 'fill', source: 'buildings', filter: ['==', ['get', 'type'], 'evacuation'], paint: { 'fill-color': SAFE_COLOR, 'fill-opacity': 0.35 } },
                    {
                        id: 'buildings',
                        type: 'fill',
                        source: 'buildings',
                        filter: ['in', ['get', 'type'], ['literal', AFFECTED_TYPES]],
                        paint: { 'fill-color': COLORS.building, 'fill-opacity': 0.75, 'fill-outline-color': '#7f3229' },
                    },
                    { id: 'affected', type: 'fill', source: 'buildings', filter: affectedFilter, paint: { 'fill-color': ALARM_COLOR, 'fill-opacity': 0.9 } },
                    { id: 'affected-outline', type: 'line', source: 'buildings', filter: affectedFilter, paint: { 'line-color': '#3d0a10', 'line-width': 3 } },
                    { id: 'danger-fill', type: 'fill', source: 'danger', paint: { 'fill-color': ALARM_COLOR, 'fill-opacity': 0.16 } },
                    { id: 'danger-outline', type: 'line', source: 'danger', paint: { 'line-color': ALARM_COLOR, 'line-width': 2, 'line-dasharray': [2, 1.5] } },
                    { id: 'accuracy', type: 'fill', source: 'accuracy', paint: { 'fill-color': ['get', 'color'], 'fill-opacity': 0.12 } },
                    { id: 'accuracy-outline', type: 'line', source: 'accuracy', paint: { 'line-color': ['get', 'color'], 'line-opacity': 0.5, 'line-width': 1 } },
                ],
            },
            bounds: campusBounds,
            minZoom: 15.5,
            maxZoom: 21,
            attributionControl: false,
            dragRotate: false,
            pitchWithRotate: false,
        });
        map.touchZoomRotate.disableRotation();
        map.addControl(new maplibregl.NavigationControl({ showCompass: false }), 'top-right');
        // The frame's size settles after layout (grid, sticky, fonts): keep the canvas filling it.
        new ResizeObserver(() => map.resize()).observe(container);
        await new Promise((resolve) => map.once('load', resolve));
        map.resize();
        map.fitBounds(campusBounds, { padding: 24, duration: 0 });

        if (incident) {
            const pin = document.createElement('div');
            pin.className = 'text-[#B3121B] drop-shadow-md';
            pin.setAttribute('aria-hidden', 'true');
            pin.innerHTML =
                '<svg viewBox="0 0 24 24" class="size-9" fill="currentColor" stroke="#fff" stroke-width="1.5"><path d="M12 22s-7-6.4-7-12a7 7 0 0 1 14 0c0 5.6-7 12-7 12z"/><circle cx="12" cy="10" r="2.5" fill="#fff"/></svg>';
            new maplibregl.Marker({ element: pin, anchor: 'bottom' }).setLngLat(incident).addTo(map);
        }
    } catch (error) {
        console.error('Help map failed to load', error);
        container.closest('[data-help-map-frame]')?.setAttribute('data-failed', '');
        map = null;
    }

    // ---------- Cards and dots, after every refresh of the queue
    const known = new Set();
    let firstLoad = true;
    let requests = [];

    const cardOf = (id) => workspace.querySelector(`[data-help-card="${id}"]`);
    const highlight = (id) => {
        const card = cardOf(id);
        if (!card) return;
        card.setAttribute('data-highlight', '');
        setTimeout(() => card.removeAttribute('data-highlight'), 2500);
    };

    function focusOnMap(id) {
        const request = requests.find((r) => r.id === id);
        if (!map || !request || !hasPosition(request)) return;
        map.flyTo({ center: [request.longitude, request.latitude], zoom: Math.max(map.getZoom(), 19), duration: 700 });
        container.scrollIntoView({ block: 'nearest', behavior: 'smooth' });
    }

    function render() {
        ({ requests } = readHelpPoints(pointsRoot));
        const open = requests.filter((r) => r.status !== 'helped').length;
        const fresh = newRequestIds(known, requests, firstLoad);
        requests.forEach((r) => known.add(r.id));
        firstLoad = false;

        // Where each person is, on their card.
        requests.forEach((request) => {
            const where = workspace.querySelector(`[data-help-where="${request.id}"]`);
            const text = describeWhere(request, buildings, incident);
            if (where && text) where.textContent = text;
        });

        if (live) {
            document.title = titleWithCount(baseTitle, open);
            const waiting = $('[data-help-waiting]');
            if (waiting) {
                waiting.hidden = open === 0;
                waiting.textContent = `${open} waiting`;
            }
            if (fresh.length) {
                if (soundOn) beep(ensureAudio());
                fresh.forEach(highlight);
                if (status) status.textContent = fresh.length === 1 ? 'Someone new asked for help.' : `${fresh.length} people asked for help.`;
            }
        }

        if (!map) return;
        // The live map leaves out the helped; the report shows everyone.
        const shown = requests.filter((r) => hasPosition(r) && (!live || r.status !== 'helped'));
        const shownIds = new Set(shown.map((r) => r.id));
        markers.forEach((marker, id) => {
            if (!shownIds.has(id)) {
                marker.remove();
                markers.delete(id);
            }
        });
        shown.forEach((request) => {
            const point = [request.longitude, request.latitude];
            const existing = markers.get(request.id);
            if (existing) {
                existing.setLngLat(point);
                updateDot(existing.getElement(), request);
                return;
            }
            const element = dotElement(request);
            element.addEventListener('click', (event) => {
                event.stopPropagation();
                const card = cardOf(request.id);
                card?.scrollIntoView({ block: 'nearest', behavior: 'smooth' });
                card?.focus({ preventScroll: true });
                highlight(request.id);
            });
            markers.set(request.id, new maplibregl.Marker({ element, anchor: 'top', offset: [0, -12] }).setLngLat(point).addTo(map));
        });
        map.getSource('accuracy')?.setData({
            type: 'FeatureCollection',
            features: shown
                .filter((r) => r.accuracy_m > 5)
                .map((r) => ({ ...circlePolygon([r.longitude, r.latitude], Math.min(r.accuracy_m, 300)), properties: { color: DOT_COLORS[r.status] ?? ALARM_COLOR } })),
        });
    }

    workspace.addEventListener('click', (event) => {
        const button = event.target.closest('[data-help-focus]');
        if (button) focusOnMap(Number(button.dataset.helpFocus));
    });

    // "I'm responding" and "Mark helped" without leaving the page (without JavaScript they are plain forms).
    workspace.addEventListener('submit', async (event) => {
        const form = event.target.closest('form[data-help-action]');
        if (!form) return;
        event.preventDefault();
        const button = form.querySelector('button');
        button.disabled = true;
        try {
            const response = await fetch(form.action, {
                method: 'POST',
                body: new FormData(form),
                headers: { Accept: 'application/json' },
                credentials: 'same-origin',
            });
            const body = await response.json().catch(() => ({}));
            if (status && body.message) status.textContent = body.message;
        } catch {
            if (status) status.textContent = 'Could not reach the server. Try again.';
        } finally {
            button.disabled = false;
            live?.dispatchEvent(new Event('live-panel-refresh'));
        }
    });

    live?.addEventListener('live-panel-updated', render);
    render();
}
