// Admin lists (buildings, rooms): search as you type over every row already on the page, then show 10 rows per page.
// Nothing is requested from the server: every row is in the page, and the rows are hidden or shown here.

// Does the text hold every word of the query? Case and extra spaces don't matter, and the order of words doesn't.
export function matchesQuery(text, query) {
    const words = query.trim().toLowerCase().split(/\s+/).filter(Boolean);
    const haystack = text.toLowerCase();

    return words.every((word) => haystack.includes(word));
}

// One page of items. Page numbers start at 1 and are kept within the pages there are (an empty list has one page).
export function paginate(items, page, size) {
    const pages = Math.max(1, Math.ceil(items.length / size));
    const current = Math.min(Math.max(1, page), pages);
    const start = (current - 1) * size;

    return { items: items.slice(start, start + size), page: current, pages, start };
}

const BUTTON = 'inline-flex min-w-9 items-center justify-center rounded-lg border px-3 py-1.5 text-sm font-semibold transition';
const BUTTON_IDLE = 'border-border text-primary hover:border-secondary hover:bg-secondary/10';
const BUTTON_CURRENT = 'border-primary bg-primary text-white';
const BUTTON_OFF = 'border-border text-text-muted/60';

export function initTableSearch(root) {
    const input = root.querySelector('[data-table-search]');
    const rows = [...root.querySelectorAll('[data-row]')];
    const size = Number(root.dataset.pageSize ?? 10);
    const pager = root.querySelector('[data-pager]');
    const summary = root.querySelector('[data-summary]');
    const noMatch = root.querySelector('[data-no-match]');
    let page = 1;

    const button = (label, target, { current = false, disabled = false, text = label } = {}) => {
        const el = document.createElement('button');
        el.type = 'button';
        el.textContent = text;
        el.setAttribute('aria-label', label);
        el.className = `${BUTTON} ${disabled ? BUTTON_OFF : current ? BUTTON_CURRENT : BUTTON_IDLE}`;
        el.disabled = disabled;
        if (current) el.setAttribute('aria-current', 'page');
        el.addEventListener('click', () => {
            page = target;
            render();
        });

        return el;
    };

    const renderPager = (view) => {
        pager.hidden = view.pages <= 1;
        const buttons = [button('Previous page', view.page - 1, { text: 'Previous', disabled: view.page === 1 })];
        for (let number = 1; number <= view.pages; number++) {
            buttons.push(button(`Page ${number}`, number, { text: String(number), current: number === view.page }));
        }
        buttons.push(button('Next page', view.page + 1, { text: 'Next', disabled: view.page === view.pages }));
        pager.replaceChildren(...buttons);
    };

    const render = () => {
        const matches = rows.filter((row) => matchesQuery(row.dataset.search ?? '', input?.value ?? ''));
        const view = paginate(matches, page, size);
        page = view.page;

        const shown = new Set(view.items);
        rows.forEach((row) => {
            row.hidden = !shown.has(row);
        });

        if (noMatch) noMatch.hidden = matches.length > 0;
        if (summary) {
            summary.textContent = matches.length === 0
                ? '0 matches'
                : `Showing ${view.start + 1} to ${view.start + view.items.length} of ${matches.length}`;
        }
        if (pager) renderPager(view);
    };

    input?.addEventListener('input', () => {
        page = 1;
        render();
    });
    render();
}
