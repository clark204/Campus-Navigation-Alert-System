// Settings → Account → Email (partials/account-email-form): a verified email shows no button until a different address
// is typed ("Send code"). An unverified email shows "Verify email" while the field holds it, "Send code" once it doesn't.
// The server renders the same first state, and treats the current email the same way if it is submitted anyway.

/**
 * What the submit button shows for the typed value.
 *
 * @returns {{ hidden: boolean, label: string }}
 */
export function submitState(value, currentEmail, verified) {
    const typedCurrent = value.trim().toLowerCase() === currentEmail.trim().toLowerCase();

    if (!typedCurrent) {
        return { hidden: false, label: 'Send code' };
    }

    return verified ? { hidden: true, label: 'Send code' } : { hidden: false, label: 'Verify email' };
}

export function initEmailChange(form) {
    const input = form.elements.namedItem('email');
    const row = form.querySelector('[data-email-change-submit]');
    const label = form.querySelector('[data-email-change-label]');
    const { currentEmail } = form.dataset;
    const verified = form.dataset.verified === 'true';

    const sync = () => {
        const state = submitState(input.value, currentEmail, verified);
        row.hidden = state.hidden;
        label.textContent = state.label;
    };

    input.addEventListener('input', sync);
    sync();
}
