// "Too many sign-in attempts. Please try again in 49 seconds." counts down live (login page; the server flashes the
// wait as session('throttle'), AppServiceProvider::tooManyAttempts). Not a live region: a screen reader would read
// every second. The server still enforces the wait.
import { secondsLeft } from './resend-cooldown.js';

/** The wait sentence for the seconds left (0 or less = the wait is over). */
export function retryText(seconds) {
    if (seconds <= 0) return 'You can try again now.';

    return `Please try again in ${seconds} ${seconds === 1 ? 'second' : 'seconds'}.`;
}

export function initRetryCountdown(element) {
    const seconds = Number(element.dataset.retryCountdown) || 0;
    // Counted from a deadline, so a tab the browser throttled in the background still shows the right time.
    const deadline = Date.now() + seconds * 1000;

    const tick = () => {
        // Typing in the field removes the error (data-clear-errors): stop with it.
        if (!element.isConnected) return;
        const left = secondsLeft(deadline);
        element.textContent = retryText(left);
        if (left > 0) setTimeout(tick, ((deadline - Date.now()) % 1000 || 1000) + 20);
    };
    tick();
}
