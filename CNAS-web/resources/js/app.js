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

// Clear server errors as soon as the user edits a field. Each error lists the fields it belongs to in
// data-error-for (e.g. "login password" for "Invalid credentials."); typing in any of them removes the
// message and the invalid state from all of them.
document.querySelectorAll('form[data-clear-errors]').forEach((form) => {
    form.addEventListener('input', (event) => {
        const name = event.target.name;
        if (!name) {
            return;
        }
        form.querySelectorAll('[data-error-for]').forEach((error) => {
            const fields = error.dataset.errorFor.split(/\s+/);
            if (!fields.includes(name)) {
                return;
            }
            fields.forEach((field) => {
                const input = form.elements.namedItem(field);
                if (input instanceof HTMLElement && input.getAttribute('aria-describedby') === error.id) {
                    input.removeAttribute('aria-invalid');
                    input.removeAttribute('aria-describedby');
                }
            });
            error.remove();
        });
    });
});

// Ask before submitting forms that change something important (e.g. the current academic term). Registered before
// data-submit-once so a cancelled confirm doesn't leave the submit button disabled. The message is read at submit
// time, so scripts may update it (the alert send form does).
document.querySelectorAll('form[data-confirm]').forEach((form) => {
    form.addEventListener('submit', (event) => {
        if (!window.confirm(form.dataset.confirm)) {
            event.preventDefault();
            event.stopImmediatePropagation();
        }
    });
});

// Block double submits on forms that create something (the server is idempotent too). A button with
// data-loading-text also shows a spinner ([data-submit-spinner]) and that text ([data-submit-label]) while the
// request runs (the sign-in button).
document.querySelectorAll('form[data-submit-once]').forEach((form) => {
    const buttons = form.querySelectorAll('[type="submit"]');
    const setBusy = (busy) => buttons.forEach((button) => {
        button.disabled = busy;
        const label = button.querySelector('[data-submit-label]');
        if (!label || !button.dataset.loadingText) return;
        label.dataset.idleText ??= label.textContent;
        label.textContent = busy ? button.dataset.loadingText : label.dataset.idleText;
        button.querySelector('[data-submit-spinner]')?.toggleAttribute('hidden', !busy);
    });

    form.addEventListener('submit', () => setBusy(true));
    // Coming back with the Back button can restore the page as it was left (busy): make it usable again.
    window.addEventListener('pageshow', (event) => {
        if (event.persisted) setBusy(false);
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

// Sidebar (admin console and portal): off-canvas below lg, opened from the top bar menu button.
const sidebar = document.querySelector('[data-sidebar]');
if (sidebar) {
    const menuButton = document.querySelector('[data-sidebar-menu]');
    const backdrop = document.querySelector('[data-sidebar-backdrop]');
    const setOpen = (open) => {
        sidebar.toggleAttribute('data-open', open);
        backdrop.hidden = !open;
        menuButton.setAttribute('aria-expanded', String(open));
        menuButton.setAttribute('aria-label', open ? 'Close navigation' : 'Open navigation');
    };
    menuButton.addEventListener('click', () => setOpen(!sidebar.hasAttribute('data-open')));
    backdrop.addEventListener('click', () => setOpen(false));
    document.addEventListener('keydown', (event) => {
        if (event.key === 'Escape' && sidebar.hasAttribute('data-open')) {
            setOpen(false);
            menuButton.focus();
        }
    });
}

// Top bar menus: the notification bell and the account menu (both <details>, so they open without JS). Close one on
// Escape, on a click outside it (which also means opening the other closes it), and after following one of its links.
document.querySelectorAll('[data-account-menu], [data-notification-menu]').forEach((menu) => {
    const close = () => {
        menu.open = false;
    };
    document.addEventListener('click', (event) => {
        if (menu.open && !menu.contains(event.target)) close();
    });
    menu.addEventListener('keydown', (event) => {
        if (event.key === 'Escape' && menu.open) {
            close();
            menu.querySelector('summary').focus();
        }
    });
    menu.querySelectorAll('[data-account-menu-link], [data-menu-link]').forEach((link) => link.addEventListener('click', close));
});

// Class form (My schedule / My classes): the "Where does it meet?" box shows only for "Somewhere not on the map".
document.querySelectorAll('[data-room-picker]').forEach((picker) => {
    const select = picker.querySelector('select');
    const other = picker.querySelector('[data-room-other]');
    const sync = () => {
        const shown = select.value === 'other';
        other.hidden = !shown;
        other.querySelector('input').disabled = !shown;
    };
    select.addEventListener('change', () => {
        sync();
        if (select.value === 'other') other.querySelector('input').focus();
    });
    sync();
});

// Admin > Accounts: the create form (role fields, preview, password tools) and the Danger zone's typed confirmation.
const accountCreate = document.querySelector('[data-account-create]');
const accountDelete = document.querySelector('[data-account-delete]');
const accountsSearch = document.querySelector('[data-accounts-search]');
const temporaryPassword = document.querySelector('form[data-password-tools]');
if (accountCreate || accountDelete || accountsSearch || temporaryPassword) {
    import('./accounts.js').then(({ initAccountCreate, initAccountDelete, initAccountsSearch, initPasswordTools }) => {
        if (accountCreate) initAccountCreate(accountCreate);
        if (accountDelete) initAccountDelete(accountDelete);
        if (accountsSearch) initAccountsSearch(accountsSearch);
        if (temporaryPassword) initPasswordTools(temporaryPassword);
    });
}

// Admin "End alert": picking "All clear" or "False alarm" swaps in its message, unless the admin already wrote one.
document.querySelectorAll('form[data-resolve-form]').forEach((form) => {
    const message = form.elements.namedItem('resolved_message');
    let shown = form.querySelector('input[name="resolution"]:checked')?.dataset.defaultMessage;
    form.addEventListener('change', (event) => {
        if (event.target.name !== 'resolution') return;
        if (message.value.trim() === shown?.trim()) message.value = event.target.dataset.defaultMessage;
        shown = event.target.dataset.defaultMessage;
    });
});

// Admin "Send emergency alert": the map to drop the incident pin on.
const alertPicker = document.querySelector('[data-alert-picker]');
if (alertPicker) {
    import('./alert-picker.js').then(({ initAlertPicker }) => initAlertPicker(alertPicker));
}

// Admin top bar search: suggestions while typing, "/" to jump to it (the form alone already opens the results page).
// Admin > Alerts: the "I'm safe" panel refreshes itself every 10 s.
const safetyLive = document.querySelector('[data-safety-live]');
if (safetyLive) {
    import('./alert-safety-live.js').then(({ initSafetyLive }) => initSafetyLive(safetyLive));
}

// Sidebar badges kept up to date (the admin's open concerns), every 30 s while the page is open.
const liveBadges = [...document.querySelectorAll('[data-live-badge]')];
if (liveBadges.length) {
    import('./admin-badges.js').then(({ initLiveBadges }) => initLiveBadges(liveBadges));
}

const adminSearch = document.querySelector('[data-admin-search]');
if (adminSearch) {
    import('./admin-search.js').then(({ initAdminSearch }) => initAdminSearch(adminSearch));
}

// Toast notifications flashed by the server (partials/toasts), e.g. "Email verified".
const toastRegion = document.querySelector('[data-toast-region]');
if (toastRegion?.querySelector('[data-toast]')) {
    import('./toasts.js').then(({ initToasts }) => initToasts(toastRegion));
}

// "Verify your email" and Settings → email change: "Resend code" waits out the cooldown with a countdown.
const retryCountdowns = document.querySelectorAll('[data-retry-countdown]');
if (retryCountdowns.length) {
    import('./retry-countdown.js').then(({ initRetryCountdown }) => retryCountdowns.forEach(initRetryCountdown));
}

const emailChangeForm = document.querySelector('form[data-email-change]');
if (emailChangeForm) {
    import('./email-change.js').then(({ initEmailChange }) => initEmailChange(emailChangeForm));
}

const resendButtons = document.querySelectorAll('[data-resend-cooldown]');
if (resendButtons.length) {
    import('./resend-cooldown.js').then(({ initResendCooldown }) => resendButtons.forEach(initResendCooldown));
}

// Admin Settings: the tabs (no reload, unsaved-change warnings) and the live push subscriber count.
const settingsTabs = document.querySelector('[data-settings-tabs]');
if (settingsTabs) {
    import('./settings-tabs.js').then(({ initSettingsTabs }) => initSettingsTabs(settingsTabs));
}
// A room's or building's QR code page (the "QR code" button on Admin > Rooms and Buildings): Print, Download PNG, doors.
const qrSheet = document.querySelector('[data-qr-sheet]');
if (qrSheet) {
    import('./qr-print.js').then(({ initQrPrint }) => initQrPrint(qrSheet));
}

// Portal Settings → Navigation: "Voice directions" for this browser (/navigate wires its own copy in navigation/main.js).
const voiceDirections = document.querySelector('[data-voice-directions]');
if (voiceDirections) {
    import('./voice-directions.js').then(({ initVoiceDirections }) => initVoiceDirections(voiceDirections));
}

const pushSubscribersLive = document.querySelector('[data-push-subscribers-live]');
if (pushSubscribersLive) {
    import('./push-subscribers-live.js').then(({ initPushSubscribersLive }) => initPushSubscribersLive(pushSubscribersLive));
}

// Admin "Edit on map": every 360° photo pin, the selected photo's north and the "Save all" (desktop only).
const panoramaMapEditor = document.querySelector('[data-panorama-map-editor]');
if (panoramaMapEditor) {
    import('./panorama-map-editor.js').then(({ initPanoramaMapEditor }) => initPanoramaMapEditor(panoramaMapEditor));
}

// Admin lists (buildings, rooms): search as you type and 10 rows per page, all in the page (table-search.js).
const searchableLists = document.querySelectorAll('[data-table-search-root]');
if (searchableLists.length) {
    import('./table-search.js').then(({ initTableSearch }) => searchableLists.forEach(initTableSearch));
}

// Admin lists with photo thumbnails (the buildings list): hovering a thumbnail shows a larger still.
const stillLists = document.querySelectorAll('[data-still-list]');
if (stillLists.length) {
    import('./still-preview.js').then(({ initStillPreview }) => stillLists.forEach(initStillPreview));
}

// Admin alert forms (send and edit): the line under the level cards says what students get for the selection.
const severityPickers = document.querySelectorAll('[data-severity-picker]');
if (severityPickers.length) {
    import('./severity-picker.js').then(({ initSeverityPicker }) => severityPickers.forEach(initSeverityPicker));
}

// Admin building and room pages: the photo upload's file types and the 360° previews (the viewer loads when opened).
const placePhotos = document.querySelector('[data-place-photos]');
if (placePhotos) {
    import('./place-photos.js').then(({ initPlacePhotos }) => initPlacePhotos(placePhotos));
}

// Admin "360° photos" list: "Upload photo" opens the upload form in a dialog (too-big check, errors, Uploading…).
const panoramasUpload = document.querySelector('[data-upload-modal]');
if (panoramasUpload) {
    import('./panoramas-upload.js').then(({ initPanoramasUpload }) => initPanoramasUpload(panoramasUpload));
}

// Admin "360° photos" list: the preview of a photo, with its link arrows, in a modal.
const panoramasList = document.querySelector('[data-panoramas-list]');
if (panoramasList) {
    import('./panoramas-list.js').then(({ initPanoramasList }) => initPanoramasList(panoramasList));
}

// Load the map bundle only on pages that show a campus map.
if (document.querySelector('[data-campus-map]')) {
    import('./campus-map.js');
}
