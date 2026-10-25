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

// Load the map bundle only on pages that show a campus map.
if (document.querySelector('[data-campus-map]')) {
    import('./campus-map.js');
}
