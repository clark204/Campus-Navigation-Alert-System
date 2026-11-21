// Admin Settings tabs. The server opens the tab named in ?tab= (Contact details by default) and every tab is a plain
// link, so the page works without JavaScript. With it, a tab switches without a reload, the URL follows (?tab=) so a
// reload stays on the same tab, and the arrow keys, Home and End move between tabs.
//
// Unsaved changes: switching tabs never loses typed values (hidden panels keep them), and the tab shows a dot. Saving
// one form reloads the page, so it asks first when another form has unsaved changes, and leaving the page warns too.

const TRACKED_FIELDS = 'input:not([type="hidden"]):not([type="submit"]):not([type="button"]), textarea, select';

/** The tab index a key moves to, or null for keys the tab list doesn't handle. Wraps at both ends. */
export function nextTabIndex(key, index, count) {
    switch (key) {
        case 'ArrowRight':
        case 'ArrowDown':
            return (index + 1) % count;
        case 'ArrowLeft':
        case 'ArrowUp':
            return (index - 1 + count) % count;
        case 'Home':
            return 0;
        case 'End':
            return count - 1;
        default:
            return null;
    }
}

/** Whether a field differs from the value the page loaded with. */
export function isFieldDirty(field) {
    if (field.type === 'checkbox' || field.type === 'radio') return field.checked !== field.defaultChecked;
    if (field.tagName === 'SELECT') return [...field.options].some((option) => option.selected !== option.defaultSelected);
    return field.value !== field.defaultValue;
}

/** The confirm message when saving one form would discard unsaved changes on these tabs. */
export function unsavedChangesMessage(tabNames) {
    const where = tabNames.length > 1 ? `${tabNames.slice(0, -1).join(', ')} and ${tabNames.at(-1)}` : tabNames[0];
    return `You have unsaved changes in ${where}. Saving this will discard them. Continue?`;
}

export function initSettingsTabs(tablist) {
    const tabs = [...tablist.querySelectorAll('[role="tab"]')];
    const panelOf = (tab) => document.getElementById(tab.getAttribute('aria-controls'));
    const forms = tabs.flatMap((tab) => [...panelOf(tab).querySelectorAll('form')]);
    const isFormDirty = (form) => [...form.querySelectorAll(TRACKED_FIELDS)].some(isFieldDirty);
    const tabOf = (form) => tabs.find((tab) => panelOf(tab).contains(form));
    const tabName = (tab) => tab.querySelector('[data-tab-label]').textContent.trim();
    let leaving = false;

    const select = (tab, { focus = false } = {}) => {
        for (const other of tabs) {
            const selected = other === tab;
            other.setAttribute('aria-selected', String(selected));
            other.tabIndex = selected ? 0 : -1;
            panelOf(other).hidden = !selected;
        }
        if (focus) tab.focus();
        history.replaceState(history.state, '', tab.href);
        panelOf(tab).dispatchEvent(new CustomEvent('settings:tab-shown'));
    };

    // Roving focus: only the open tab is in the Tab order; the arrow keys reach the others.
    for (const tab of tabs) {
        tab.tabIndex = tab.getAttribute('aria-selected') === 'true' ? 0 : -1;
        tab.addEventListener('click', (event) => {
            if (event.ctrlKey || event.metaKey || event.shiftKey || event.button !== 0) return; // Open in a new tab.
            event.preventDefault();
            select(tab);
        });
    }
    tablist.addEventListener('keydown', (event) => {
        const next = nextTabIndex(event.key, tabs.indexOf(document.activeElement), tabs.length);
        if (next === null || !tabs.includes(document.activeElement)) return;
        event.preventDefault();
        select(tabs[next], { focus: true });
    });

    const showDirtyTabs = () => {
        const dirty = new Set(forms.filter(isFormDirty).map(tabOf));
        for (const tab of tabs) tab.querySelector('[data-tab-dirty]').hidden = !dirty.has(tab);
    };
    document.addEventListener('input', showDirtyTabs);
    document.addEventListener('change', showDirtyTabs);

    // Before any form on the page is sent (capture phase: before the confirm and submit-once handlers in app.js).
    document.addEventListener(
        'submit',
        (event) => {
            const others = forms.filter((form) => form !== event.target && isFormDirty(form));
            if (!others.length) return;
            const names = [...new Set(others.map((form) => tabName(tabOf(form))))];
            if (!window.confirm(unsavedChangesMessage(names))) {
                event.preventDefault();
                event.stopImmediatePropagation();
            }
        },
        true,
    );
    // After every handler: a submit nobody cancelled is leaving the page on purpose.
    document.addEventListener('submit', (event) => {
        if (!event.defaultPrevented) leaving = true;
    });
    window.addEventListener('beforeunload', (event) => {
        if (leaving || !forms.some(isFormDirty)) return;
        event.preventDefault();
        event.returnValue = '';
    });
}
