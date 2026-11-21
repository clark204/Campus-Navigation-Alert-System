// The "Upload photo" dialog on the admin 360° photos list. The rules are in panorama-upload-state.js; this drives the
// page: focus goes to the file picker when it opens and back to the button when it closes; Esc, the close buttons and a
// click outside close it (not while a photo is being sent); the button says "Uploading…" and cannot be pressed twice.
// A page that came back with the server's errors opens the dialog again with them.
import {
    closeModal,
    createUploadModal,
    openModal,
    pickError,
    startUpload,
    submitAllowed,
    uploadFailed,
} from './panorama-upload-state.js';

export function initPanoramasUpload(modal) {
    const form = modal.querySelector('form[data-panorama-upload]');
    const input = form.elements.namedItem('photo');
    const error = modal.querySelector('[data-upload-error]');
    const submit = modal.querySelector('[data-upload-submit]');
    const idleLabel = submit.textContent.trim();
    const trigger = document.querySelector('[data-open-upload]');
    const maxBytes = Number(form.dataset.maxBytes);
    const tooBigMessage = form.dataset.tooBig;
    const focusable = () => [...modal.querySelectorAll('input, button, select, textarea, a[href]')].filter((el) => !el.disabled && el.offsetParent !== null);

    let state = createUploadModal();
    let returnFocus = trigger;

    const render = () => {
        modal.hidden = !state.open;
        submit.disabled = state.uploading;
        submit.setAttribute('aria-busy', String(state.uploading));
        submit.textContent = state.uploading ? 'Uploading…' : idleLabel;
        error.textContent = state.error;
        input.toggleAttribute('aria-invalid', Boolean(state.error));
        document.documentElement.classList.toggle('overflow-hidden', state.open);
    };

    const open = () => {
        returnFocus = document.activeElement instanceof HTMLElement ? document.activeElement : trigger;
        state = openModal(state);
        render();
        input.focus();
    };

    const close = () => {
        const next = closeModal(state);
        if (next.open === state.open && state.uploading) return;
        state = next;
        render();
        returnFocus?.focus?.();
    };

    trigger?.addEventListener('click', open);
    modal.querySelectorAll('[data-upload-close]').forEach((button) => button.addEventListener('click', close));
    // A click on the dark backdrop (not on the dialog) closes it.
    modal.addEventListener('click', (event) => {
        if (event.target === modal) close();
    });
    modal.addEventListener('keydown', (event) => {
        if (event.key === 'Escape') {
            event.preventDefault();
            close();
            return;
        }
        // Tab stays inside the dialog.
        if (event.key === 'Tab' && state.open) {
            const items = focusable();
            if (!items.length) return;
            const first = items[0];
            const last = items.at(-1);
            if (event.shiftKey && document.activeElement === first) {
                event.preventDefault();
                last.focus();
            } else if (!event.shiftKey && document.activeElement === last) {
                event.preventDefault();
                first.focus();
            }
        }
    });

    // The file picked: a file over the limit is refused here, with the server's own message.
    input.addEventListener('change', () => {
        state = { ...state, error: pickError(input.files[0]?.size ?? 0, maxBytes, tooBigMessage) };
        render();
    });

    // Sending: only when the file is within the limit and no send is already in progress.
    form.addEventListener('submit', (event) => {
        const size = input.files[0]?.size ?? 0;
        if (!submitAllowed(state, size, maxBytes)) {
            event.preventDefault();
            if (size > maxBytes) {
                state = { ...state, error: tooBigMessage };
                render();
            }
            return;
        }
        state = startUpload(state);
        render();
    });

    // The server answered with the upload's errors: the dialog opens again with them, so they are not lost.
    if (modal.hasAttribute('data-reopen')) {
        state = uploadFailed(state, error.textContent.trim());
        render();
    }
    render();
}
