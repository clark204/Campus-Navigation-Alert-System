// Load the map bundle only on pages that show a campus map.
if (document.querySelector('[data-campus-map]')) {
    import('./campus-map.js');
}
