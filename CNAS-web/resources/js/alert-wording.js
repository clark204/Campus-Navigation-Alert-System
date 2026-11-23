// The alert wording fields (admin/alert-wording-fields), on the send form and Edit alert: a live counter for the title
// and "What to do now", "What to do now" required only for an emergency (like the server), and the phone preview
// (admin/alert-phone-preview): the takeover for an emergency, the toast for a warning or advisory.

/** "EMERGENCY · FIRE", "DRILL · FIRE", "WARNING · FLOOD": the same words as the push and the app. */
export function kindLabel({ severity, typeLabel, isDrill }) {
    const level = isDrill ? 'DRILL' : String(severity ?? '').toUpperCase();
    return `${level} · ${String(typeLabel ?? '').toUpperCase()}`;
}

/** "12/60". `near` once 10 or fewer characters are left. */
export function counterState(length, max) {
    return { text: `${length}/${max}`, near: max - length <= 10 };
}

export function initAlertWording(form) {
    const root = form.querySelector('[data-alert-wording]');
    const preview = root?.querySelector('[data-phone-preview]');
    if (!root || !preview) return;

    const title = form.elements.namedItem('title');
    const whatToDo = form.elements.namedItem('what_to_do');
    const optional = root.querySelector('[data-what-to-do-optional]');

    const severity = () => form.querySelector('input[name="severity"]:checked')?.value ?? preview.dataset.severity;
    const typeInput = () => form.querySelector('input[name="type"]:checked');

    function counters() {
        for (const input of [title, whatToDo]) {
            const counter = root.querySelector(`[data-count-for="${input.name}"]`);
            const { text, near } = counterState(input.value.length, Number(input.maxLength));
            counter.textContent = text;
            counter.classList.toggle('text-alarm', near);
            counter.classList.toggle('font-semibold', near);
        }
    }

    function render() {
        const type = typeInput();
        const typeValue = type?.value ?? preview.dataset.type;
        const typeLabel = type?.dataset.typeLabel ?? preview.dataset.typeLabel;
        const drill = form.querySelector('[data-picker-drill]');
        const isDrill = drill ? drill.checked : preview.hasAttribute('data-drill');
        const level = severity();

        preview.dataset.severity = level;
        preview.toggleAttribute('data-drill', isDrill);
        preview.querySelectorAll('[data-type-icon]').forEach((icon) => icon.toggleAttribute('hidden', icon.dataset.typeIcon !== typeValue));
        const fill = (name, text) => preview.querySelectorAll(`[data-preview="${name}"]`).forEach((element) => (element.textContent = text));
        fill('kind', kindLabel({ severity: level, typeLabel, isDrill }));
        fill('title', title.value.trim() || 'No title yet');
        fill('what_to_do', whatToDo.value.trim() || (level === 'emergency' ? 'Say what to do now' : ''));
        const location = form.querySelector('[data-picker-location]');
        if (location) fill('location', location.textContent.trim());

        // Required for an emergency only, like UpdateAlertRequest::wordingRules().
        whatToDo.required = level === 'emergency';
        if (optional) optional.hidden = level === 'emergency';
        counters();
    }

    form.addEventListener('input', render);
    form.addEventListener('change', render);
    // The send form's map picker changes the location without an input event.
    form.addEventListener('alert-location-change', render);
    render();
}
