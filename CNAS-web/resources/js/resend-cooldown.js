// "Resend code" buttons (partials/resend-code-button): disabled with a countdown until a new emailed code can be sent
// (EmailCodes::RESEND_SECONDS). The server enforces the wait too; this only saves a pointless click.

/** The button text for the seconds left (0 or less = ready). */
export function cooldownLabel(secondsLeft) {
    return secondsLeft > 0 ? `Resend code in ${secondsLeft} s` : 'Resend code';
}

/** Whole seconds left until the deadline (a Date.now() value), never below 0. */
export function secondsLeft(deadline, now = Date.now()) {
    return Math.max(0, Math.ceil((deadline - now) / 1000));
}

export function initResendCooldown(button) {
    const label = button.querySelector('[data-resend-label]');
    const seconds = Number(button.dataset.resendCooldown) || 0;
    if (seconds <= 0) return;

    // Counted from a deadline, so a tab the browser throttled in the background still shows the right time.
    const deadline = Date.now() + seconds * 1000;
    const tick = () => {
        const left = secondsLeft(deadline);
        label.textContent = cooldownLabel(left);
        button.disabled = left > 0;
        // Wake up just after the next whole second.
        if (left > 0) setTimeout(tick, ((deadline - Date.now()) % 1000 || 1000) + 20);
    };
    tick();
}
