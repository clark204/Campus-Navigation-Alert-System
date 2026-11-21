// The alert level picker on the admin send and edit forms: the cards are server-rendered (admin/severity-cards); the
// one muted line under them shows what students get for the level chosen, and changes with the selection.
export function initSeverityPicker(root) {
    const line = root.querySelector('[data-students-get]');
    const update = () => {
        line.textContent = root.querySelector('input[name="severity"]:checked')?.dataset.studentsGet ?? '';
    };

    root.addEventListener('change', (event) => {
        if (event.target.name === 'severity') update();
    });
    update();
}
