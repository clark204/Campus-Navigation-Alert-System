// Admin > Accounts: the "Create account" form (fields that follow the role, the live preview card, Generate / Show /
// Copy for the temporary password) and the Danger zone's type-the-email check before deleting. The server checks
// everything again; without JavaScript every field shows and the forms still work.

// No look-alike characters (0/O, 1/l/I), so the password can be read out or written down.
const LETTERS = 'abcdefghijkmnpqrstuvwxyzABCDEFGHJKLMNPQRSTUVWXYZ';
const DIGITS = '23456789';

const randomIndex = (max) => crypto.getRandomValues(new Uint32Array(1))[0] % max;

// A temporary password that meets Password::defaults() (8+ characters, letters and numbers): `length` characters with
// at least two digits and two letters, in a random order. `pick(max)` returns 0..max-1 (tests pass their own).
export function generatePassword(length = 12, pick = randomIndex) {
    const all = LETTERS + DIGITS;
    const chars = [
        LETTERS[pick(LETTERS.length)],
        LETTERS[pick(LETTERS.length)],
        DIGITS[pick(DIGITS.length)],
        DIGITS[pick(DIGITS.length)],
        ...Array.from({ length: Math.max(0, length - 4) }, () => all[pick(all.length)]),
    ];
    for (let i = chars.length - 1; i > 0; i--) {
        const j = pick(i + 1);
        [chars[i], chars[j]] = [chars[j], chars[i]];
    }
    return chars.join('');
}

export function initialsOf(first, last) {
    const initials = `${first.trim().charAt(0)}${last.trim().charAt(0)}`.toUpperCase();
    return initials || '?';
}

// The Danger zone's check, like the server's: the account's email, ignoring case and surrounding spaces.
export const matchesConfirmation = (typed, expected) => typed.trim().toLowerCase() === expected.trim().toLowerCase();

// Whether a field marked data-for-roles="student faculty" shows for this role.
export const showsForRole = (forRoles, role) => forRoles.split(/\s+/).includes(role);

// The Accounts list URL for these filters: an empty or spaces-only search is left out, and paging starts again.
export function listUrl(action, params) {
    const url = new URL(action, 'http://localhost');
    for (const [key, value] of Object.entries(params)) {
        const text = String(value ?? '').trim();
        if (text) url.searchParams.set(key, text);
    }
    url.searchParams.delete('page');
    return action.startsWith('http') ? url.toString() : `${url.pathname}${url.search}`;
}

// A filter link (role chips, the role bar) with the current search put in, or taken out when it's empty.
export function withSearch(href, query) {
    const url = new URL(href, 'http://localhost');
    if (query.trim()) url.searchParams.set('q', query.trim());
    else url.searchParams.delete('q');
    return href.startsWith('http') ? url.toString() : `${url.pathname}${url.search}`;
}

const LIVE_SEARCH_DELAY_MS = 250;

// Admin > Accounts: the search runs as you type. The server sends back only the list and its pages
// (X-Accounts-Results), which replace the old ones; the address bar and the filter links follow the search. The pages
// load the same way. If a request fails, the browser goes to the page normally.
export function initAccountsSearch(form, fetchImpl = (...args) => fetch(...args)) {
    const input = form.elements.namedItem('q');
    const results = document.querySelector('[data-accounts-results]');
    const busy = form.querySelector('[data-search-busy]');
    let timer = 0;
    let controller = null;

    const load = async (url) => {
        controller?.abort();
        controller = new AbortController();
        results.setAttribute('aria-busy', 'true');
        busy.hidden = false;
        try {
            const response = await fetchImpl(url, {
                headers: { [form.dataset.resultsHeader]: '1', Accept: 'text/html' },
                credentials: 'same-origin',
                signal: controller.signal,
            });
            if (!response.ok) throw new Error(`Accounts: HTTP ${response.status}`);
            // Our own server's Blade output for this same list (every value escaped), from this page's own URL.
            results.innerHTML = await response.text();
            history.replaceState(null, '', url);
            document.querySelectorAll('[data-keeps-search]').forEach((link) => {
                link.href = withSearch(link.getAttribute('href'), input.value);
            });
        } catch (error) {
            if (error.name !== 'AbortError') window.location.assign(url);
            return;
        }
        results.removeAttribute('aria-busy');
        busy.hidden = true;
    };
    const search = () => load(listUrl(form.action, Object.fromEntries(new FormData(form))));

    input.addEventListener('input', () => {
        clearTimeout(timer);
        timer = setTimeout(search, LIVE_SEARCH_DELAY_MS);
    });
    form.addEventListener('submit', (event) => {
        event.preventDefault();
        clearTimeout(timer);
        search();
    });
    results.addEventListener('click', (event) => {
        const link = event.target.closest('a');
        if (!link || event.ctrlKey || event.metaKey || event.shiftKey) return;
        if (link.matches('[data-clear-search]')) {
            event.preventDefault();
            input.value = '';
            search();
            input.focus();
        } else if (link.closest('nav[aria-label="Pages"]')) {
            event.preventDefault();
            load(link.href).then(() => results.closest('section').scrollIntoView({ block: 'start', behavior: 'smooth' }));
        }
    });
}

export function initAccountCreate(form) {
    const field = (name) => form.elements.namedItem(name);
    const preview = (name) => form.closest('body').querySelector(`[data-preview="${name}"]`);
    const roleLabels = Object.fromEntries(
        [...form.querySelectorAll('[data-role-choice]')].map((input) => [input.value, input.closest('label').querySelector('.font-semibold').textContent.trim()]),
    );
    const currentRole = () => form.querySelector('[data-role-choice]:checked')?.value ?? '';

    const render = () => {
        const role = currentRole();
        form.querySelectorAll('[data-for-roles]').forEach((el) => {
            const shown = showsForRole(el.dataset.forRoles, role);
            el.hidden = !shown;
            // A hidden field is not sent (a staff account has no program; a student is never trusted).
            el.querySelectorAll('input, select').forEach((input) => (input.disabled = !shown));
        });
        const isStudent = role === 'student';
        form.querySelector('[data-id-label-student]').hidden = !isStudent;
        form.querySelector('[data-id-label-employee]').hidden = isStudent;
        form.querySelector('[data-program-optional]').hidden = isStudent;
        field('school_id').inputMode = isStudent ? 'numeric' : 'text';

        const first = field('first_name').value;
        const last = field('last_name').value;
        const name = [first, field('middle_name').value, last].map((part) => part.trim()).filter(Boolean).join(' ');
        preview('initials').textContent = initialsOf(first, last);
        preview('name').textContent = name || 'New account';
        preview('role').textContent = roleLabels[role] ?? '';
        preview('email').textContent = field('email').value.trim() || '—';
        preview('school_id').textContent = field('school_id').value.trim() || '—';
        const program = field('program_code');
        preview('program_code').textContent = !program.disabled && program.value ? program.selectedOptions[0].textContent.trim() : '—';
        const trusted = field('trusted_for_alerts');
        preview('trusted').hidden = !(trusted && !trusted.disabled && trusted.checked);
    };
    form.addEventListener('input', render);
    form.addEventListener('change', render);
    render();

    initPasswordTools(form);
}

/**
 * Temporary password tools (Create account, and "Set temporary password" on an account page): Generate fills it in
 * and shows it, Show toggles it, Copy puts it on the clipboard. Without JavaScript the buttons stay hidden and the
 * field still works.
 */
export function initPasswordTools(form) {
    const password = form.querySelector('[data-password-field]');
    const showButton = form.querySelector('[data-password-show]');
    const copyButton = form.querySelector('[data-password-copy]');
    const generateButton = form.querySelector('[data-password-generate]');
    const status = form.querySelector('[data-password-status]');
    const setShown = (shown) => {
        password.type = shown ? 'text' : 'password';
        showButton.textContent = shown ? 'Hide' : 'Show';
        showButton.setAttribute('aria-pressed', String(shown));
    };
    [generateButton, showButton].forEach((button) => (button.hidden = false));
    copyButton.hidden = !navigator.clipboard;
    generateButton.addEventListener('click', () => {
        password.value = generatePassword();
        setShown(true);
        status.textContent = 'Password generated. Copy it before you save.';
        password.dispatchEvent(new Event('input', { bubbles: true }));
    });
    showButton.addEventListener('click', () => setShown(password.type === 'password'));
    copyButton.addEventListener('click', async () => {
        if (!password.value) {
            status.textContent = 'Type or generate a password first.';
            return;
        }
        try {
            await navigator.clipboard.writeText(password.value);
            status.textContent = 'Copied.';
        } catch {
            status.textContent = 'Couldn’t copy. Select the password and copy it yourself.';
        }
    });
}

export function initAccountDelete(form) {
    const input = form.querySelector('[data-delete-confirm]');
    const submit = form.querySelector('[data-delete-submit]');
    const sync = () => {
        submit.disabled = !matchesConfirmation(input.value, form.dataset.expected);
    };
    input.addEventListener('input', sync);
    sync();
    // Opening the Danger zone puts the cursor in the box.
    form.closest('details')?.addEventListener('toggle', (event) => {
        if (event.target.open) input.focus();
    });
}
