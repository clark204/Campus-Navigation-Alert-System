// Room search for the student dashboard: type a room or building name, pick a result to open walking directions.
// Only rooms (entrances.geojson) are listed, because /navigate?to= routes to rooms, not to whole buildings.
import { ordinal } from './navigation/instructions.js';

const normalize = (text) => text.toLowerCase().replace(/\s+/g, ' ').trim();

// Whole words plus the parts of camel-cased ones, so "lab" finds "ComLab 3".
function tokensOf(text) {
    const words = text.split(/\s+/).filter(Boolean);
    const parts = words.flatMap((word) => word.split(/(?<=[a-z])(?=[A-Z])/));
    return [...new Set([...words, ...parts].map((token) => token.toLowerCase()))];
}

export function buildPlaceIndex(entrances, buildings) {
    const buildingNames = new Map(buildings.features.map((f) => [f.properties.id, f.properties.name]));
    return entrances.features.map(({ properties }) => ({
        id: properties.id,
        name: properties.name,
        buildingName: buildingNames.get(properties.building_id) ?? '',
        floor: properties.floor ?? 1,
    }));
}

// 0: the name starts with the query, 1: a word of the name does, 2: every query word starts some word.
function rank(place, query) {
    const name = place.name.toLowerCase();
    if (name.startsWith(query)) return 0;
    if (name.split(' ').some((word) => word.startsWith(query))) return 1;
    return 2;
}

export function searchPlaces(index, query, limit = 8) {
    const normalized = normalize(query);
    if (!normalized) return [];
    const words = normalized.split(' ');
    return index
        .filter((place) => {
            const tokens = tokensOf(`${place.name} ${place.buildingName}`);
            return words.every((word) => tokens.some((token) => token.startsWith(word)));
        })
        .map((place) => ({ place, score: rank(place, normalized) }))
        .sort((a, b) => a.score - b.score
            || a.place.floor - b.place.floor
            || a.place.name.localeCompare(b.place.name, undefined, { numeric: true }))
        .slice(0, limit)
        .map(({ place }) => place);
}

// Binds a [data-place-search] form: live results as links, arrow keys between them, Escape clears.
export function initPlaceSearch(root) {
    const input = root.querySelector('[data-place-search-input]');
    const list = root.querySelector('[data-place-search-results]');
    const status = root.querySelector('[data-place-search-status]');
    let index = null;
    let failed = false;

    const links = () => [...list.querySelectorAll('a')];

    const render = () => {
        const query = input.value.trim();
        list.replaceChildren();
        status.replaceChildren();
        if (!query) return;
        if (failed) {
            const mapLink = Object.assign(document.createElement('a'), {
                href: root.dataset.navigateUrl,
                className: 'font-semibold text-white underline underline-offset-4',
                textContent: 'open the map',
            });
            status.append("Search isn't available right now. You can still ", mapLink, ' and pick a room there.');
            return;
        }
        if (!index) {
            status.textContent = 'Loading rooms…';
            return;
        }
        const results = searchPlaces(index, query);
        if (!results.length) {
            status.textContent = `No rooms match "${query}". Try a room name like ComLab 3.`;
            return;
        }
        status.textContent = `${results.length} ${results.length === 1 ? 'place' : 'places'} found`;
        for (const place of results) {
            const link = document.createElement('a');
            link.href = `${root.dataset.navigateUrl}?to=${encodeURIComponent(place.id)}`;
            link.className = 'flex flex-col px-4 py-3 transition hover:bg-bg-light focus-visible:bg-secondary/10 focus-visible:outline-none';
            const name = Object.assign(document.createElement('span'), { className: 'text-sm font-semibold', textContent: place.name });
            const where = Object.assign(document.createElement('span'), {
                className: 'text-xs text-text-muted',
                textContent: [place.buildingName, `${ordinal(place.floor)} floor`].filter(Boolean).join(' · '),
            });
            link.append(name, where);
            const item = document.createElement('li');
            item.append(link);
            list.append(item);
        }
    };

    input.addEventListener('input', render);

    input.addEventListener('keydown', (event) => {
        if (event.key === 'ArrowDown' && links().length) {
            event.preventDefault();
            links()[0].focus();
        }
    });

    list.addEventListener('keydown', (event) => {
        const all = links();
        const at = all.indexOf(document.activeElement);
        if (event.key === 'ArrowDown' && at < all.length - 1) {
            event.preventDefault();
            all[at + 1].focus();
        } else if (event.key === 'ArrowUp') {
            event.preventDefault();
            (at > 0 ? all[at - 1] : input).focus();
        }
    });

    root.addEventListener('keydown', (event) => {
        if (event.key === 'Escape' && input.value) {
            event.preventDefault();
            input.value = '';
            render();
            input.focus();
        }
    });

    // Enter opens the top result; with no results the form falls through to the map's own room picker.
    root.addEventListener('submit', (event) => {
        const first = links()[0];
        if (first) {
            event.preventDefault();
            location.assign(first.href);
        }
    });

    const load = (file) => fetch(`${root.dataset.mapsUrl}/${file}`).then((res) => {
        if (!res.ok) throw new Error(`${file}: HTTP ${res.status}`);
        return res.json();
    });
    Promise.all([load('entrances.geojson'), load('buildings.geojson')])
        .then(([entrances, buildings]) => {
            index = buildPlaceIndex(entrances, buildings);
        })
        .catch((error) => {
            console.error('Room search failed to load', error);
            failed = true;
        })
        // Typing may have started before the data arrived.
        .finally(render);
}
