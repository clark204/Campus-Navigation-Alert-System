// Show/hide toggle for password inputs (login and register pages).
document.querySelectorAll('[data-password-toggle]').forEach((button) => {
    const input = document.getElementById(button.getAttribute('aria-controls'));
    button.addEventListener('click', () => {
        const showing = input.type === 'text';
        input.type = showing ? 'password' : 'text';
        button.setAttribute('aria-label', showing ? 'Show password' : 'Hide password');
        button.querySelector('[data-icon-show]').classList.toggle('hidden', !showing);
        button.querySelector('[data-icon-hide]').classList.toggle('hidden', showing);
    });
});

// Block double submits on forms that create something (the server is idempotent too).
document.querySelectorAll('form[data-submit-once]').forEach((form) => {
    form.addEventListener('submit', () => {
        form.querySelectorAll('[type="submit"]').forEach((button) => {
            button.disabled = true;
        });
    });
});

// Registration "Edit name": while closed, the name fields are disabled so they aren't sent
// and the server keeps the name split it read from the COR. Without JS they're always sent,
// pre-filled with that same split.
document.querySelectorAll('details[data-name-editor]').forEach((details) => {
    const fieldset = details.querySelector('fieldset');
    const sync = () => {
        fieldset.disabled = !details.open;
    };
    details.addEventListener('toggle', sync);
    sync();
});

// Ask before submitting forms that change something important (e.g. the current academic term).
document.querySelectorAll('form[data-confirm]').forEach((form) => {
    form.addEventListener('submit', (event) => {
        if (!window.confirm(form.dataset.confirm)) {
            event.preventDefault();
        }
    });
});

// Admin sidebar: off-canvas below lg, opened from the top bar menu button.
const adminSidebar = document.querySelector('[data-admin-sidebar]');
if (adminSidebar) {
    const menuButton = document.querySelector('[data-admin-menu]');
    const backdrop = document.querySelector('[data-admin-backdrop]');
    const setOpen = (open) => {
        adminSidebar.toggleAttribute('data-open', open);
        backdrop.hidden = !open;
        menuButton.setAttribute('aria-expanded', String(open));
        menuButton.setAttribute('aria-label', open ? 'Close navigation' : 'Open navigation');
    };
    menuButton.addEventListener('click', () => setOpen(!adminSidebar.hasAttribute('data-open')));
    backdrop.addEventListener('click', () => setOpen(false));
    document.addEventListener('keydown', (event) => {
        if (event.key === 'Escape' && adminSidebar.hasAttribute('data-open')) {
            setOpen(false);
            menuButton.focus();
        }
    });
}

// Room search on the student dashboard.
if (document.querySelector('[data-place-search]')) {
    import('./place-search.js').then(({ initPlaceSearch }) => {
        document.querySelectorAll('[data-place-search]').forEach(initPlaceSearch);
    });
}

// Load the map bundle only on pages that show a campus map.
if (document.querySelector('[data-campus-map]')) {
    import('./campus-map.js');
}
