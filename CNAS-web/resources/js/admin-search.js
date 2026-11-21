// Admin top bar search (partials/app-topbar): suggestions while typing, grouped (pages, buildings, rooms, 360° photos,
// people), from GET admin.search.suggestions. ARIA combobox: the arrow keys move through the results, Enter opens the
// chosen one (or the full results page when none is chosen), Escape closes the list. "/" anywhere jumps to the search.
// Below md the field opens from the search button as a row under the top bar.

export const MIN_LENGTH = 2;
export const DEBOUNCE_MS = 200;

/** The result to highlight after an arrow key: wraps at both ends; -1 means none. */
export function nextActiveIndex(key, index, count) {
    if (count === 0) return -1;
    if (key === 'ArrowDown') return index + 1 >= count ? 0 : index + 1;
    if (key === 'ArrowUp') return index <= 0 ? count - 1 : index - 1;
    return index;
}

/** Whether a "/" keydown should jump to the search: not while typing in a field, and not with a modifier key. */
export function isSearchShortcut({ key, ctrlKey, metaKey, altKey, target }) {
    if (key !== '/' || ctrlKey || metaKey || altKey) return false;
    const tag = target?.tagName;
    return !(tag === 'INPUT' || tag === 'TEXTAREA' || tag === 'SELECT' || target?.isContentEditable);
}

/** The words announced to screen readers for a set of groups. */
export function resultsSummary(groups) {
    const count = groups.reduce((total, group) => total + group.results.length, 0);
    return count === 0 ? 'No matches.' : `${count} ${count === 1 ? 'result' : 'results'}. Use the arrow keys to choose one.`;
}

export function initAdminSearch(form) {
    const input = form.querySelector('input[role="combobox"]');
    const panel = form.querySelector('[data-search-panel]');
    const status = form.querySelector('[data-search-status]');
    const list = form.querySelector('[data-search-list]');
    const all = form.querySelector('[data-search-all]');
    const announce = form.querySelector('[data-search-announce]');
    const toggle = document.querySelector(`[data-search-toggle][aria-controls="${form.id}"]`);
    const cache = new Map();
    let options = [];
    let active = -1;
    let timer = null;
    let request = null;

    const setOpen = (open) => {
        panel.hidden = !open;
        input.setAttribute('aria-expanded', String(open));
        if (!open) setActive(-1);
    };
    const setActive = (index) => {
        options.forEach((option, i) => option.setAttribute('aria-selected', String(i === index)));
        active = index;
        if (index >= 0) {
            input.setAttribute('aria-activedescendant', options[index].id);
            options[index].scrollIntoView({ block: 'nearest' });
        } else {
            input.removeAttribute('aria-activedescendant');
        }
    };
    const showStatus = (text) => {
        status.textContent = text;
        list.replaceChildren();
        options = [];
        setOpen(true);
    };

    const render = (data) => {
        all.href = data.all_url;
        all.textContent = `See all results for “${data.query}”`;
        list.replaceChildren();
        status.textContent = data.groups.length ? '' : `No buildings, rooms, photos, people or pages match “${data.query}”.`;
        let n = 0;
        for (const group of data.groups) {
            const section = document.createElement('div');
            section.setAttribute('role', 'group');
            section.setAttribute('aria-label', group.label);
            const heading = document.createElement('p');
            heading.setAttribute('role', 'presentation');
            heading.className = 'px-4 pt-3 pb-1 text-xs font-semibold text-text-muted';
            heading.textContent = group.label;
            section.append(heading);
            for (const result of group.results) {
                const option = document.createElement('a');
                option.id = `${input.id}-option-${n++}`;
                option.href = result.url;
                option.setAttribute('role', 'option');
                option.setAttribute('aria-selected', 'false');
                option.tabIndex = -1;
                option.className = 'block px-4 py-2 transition hover:bg-bg-light aria-selected:bg-secondary/15';
                const title = document.createElement('span');
                title.className = 'block truncate text-sm font-semibold text-text-primary';
                title.textContent = result.title;
                const detail = document.createElement('span');
                detail.className = 'block truncate text-xs text-text-muted';
                detail.textContent = result.detail;
                option.append(title, detail);
                section.append(option);
            }
            list.append(section);
        }
        options = [...list.querySelectorAll('[role="option"]')];
        announce.textContent = resultsSummary(data.groups);
        setActive(-1);
        setOpen(true);
    };

    const search = async () => {
        const query = input.value.trim();
        request?.abort();
        if (query.length < MIN_LENGTH) {
            setOpen(false);
            return;
        }
        if (cache.has(query)) {
            render(cache.get(query));
            return;
        }
        request = new AbortController();
        if (panel.hidden) showStatus('Searching…');
        try {
            const url = new URL(form.dataset.suggestUrl, location.origin);
            url.searchParams.set('q', query);
            const response = await fetch(url, { headers: { Accept: 'application/json' }, signal: request.signal });
            if (!response.ok) throw new Error(`HTTP ${response.status}`);
            const data = await response.json();
            cache.set(query, data);
            if (input.value.trim() === query) render(data);
        } catch (error) {
            if (error.name === 'AbortError') return;
            all.href = `${form.action}?q=${encodeURIComponent(query)}`;
            all.textContent = 'Open the search page';
            showStatus("Suggestions aren't loading right now. Press Enter to search on the results page.");
        }
    };

    input.addEventListener('input', () => {
        clearTimeout(timer);
        timer = setTimeout(search, DEBOUNCE_MS);
    });
    input.addEventListener('focus', () => {
        if (input.value.trim().length >= MIN_LENGTH && (options.length || status.textContent)) setOpen(true);
    });
    input.addEventListener('keydown', (event) => {
        if (event.key === 'ArrowDown' || event.key === 'ArrowUp') {
            if (panel.hidden) {
                search();
                return;
            }
            event.preventDefault();
            setActive(nextActiveIndex(event.key, active, options.length));
        } else if (event.key === 'Enter' && active >= 0) {
            event.preventDefault();
            location.assign(options[active].href);
        } else if (event.key === 'Escape') {
            if (!panel.hidden) {
                event.preventDefault();
                setOpen(false);
            } else if (form.hasAttribute('data-open')) {
                closeRow();
            } else if (input.value) {
                input.value = '';
            }
        }
    });
    form.addEventListener('submit', (event) => {
        if (!input.value.trim()) event.preventDefault();
    });
    // Mouse users: pick with a click (the link opens); keep the list while the pointer is inside it.
    list.addEventListener('mousemove', (event) => {
        const option = event.target.closest('[role="option"]');
        if (option) setActive(options.indexOf(option));
    });
    document.addEventListener('pointerdown', (event) => {
        if (!form.contains(event.target) && !toggle?.contains(event.target)) setOpen(false);
    });
    form.addEventListener('focusout', (event) => {
        if (!form.contains(event.relatedTarget)) setOpen(false);
    });

    // Small screens: the search button opens and closes the row under the top bar.
    const openRow = () => {
        form.setAttribute('data-open', '');
        toggle?.setAttribute('aria-expanded', 'true');
        input.focus();
    };
    function closeRow() {
        form.removeAttribute('data-open');
        toggle?.setAttribute('aria-expanded', 'false');
        setOpen(false);
        toggle?.focus();
    }
    if (toggle) {
        toggle.setAttribute('role', 'button');
        toggle.setAttribute('aria-expanded', 'false');
        toggle.addEventListener('click', (event) => {
            event.preventDefault();
            form.hasAttribute('data-open') ? closeRow() : openRow();
        });
    }

    document.addEventListener('keydown', (event) => {
        if (!isSearchShortcut(event)) return;
        event.preventDefault();
        if (getComputedStyle(form).display === 'none') openRow();
        input.focus();
        input.select();
    });
}
