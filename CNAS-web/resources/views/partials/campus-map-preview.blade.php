{{-- Interactive campus preview: drag to move, pinch / Ctrl + scroll to zoom, two-finger twist to rotate. --}}
<figure data-campus-map-card
    class="group relative overflow-hidden rounded-3xl border border-white/15 bg-white/5 shadow-2xl backdrop-blur-sm">
    <div data-campus-map data-maps-url="{{ asset('maps') }}"
        class="aspect-4/3 w-full sm:aspect-8/5"
        role="region" aria-label="Interactive 3D map of the Tagoloan Community College campus"></div>

    <span class="pointer-events-none absolute top-4 left-4 rounded-full bg-primary/85 px-3 py-1.5 text-[10px] font-semibold tracking-wider text-white">
        3D CAMPUS PREVIEW
    </span>
    <p data-campus-map-label hidden
        class="pointer-events-none absolute bottom-4 left-4 rounded-xl bg-white/95 px-3.5 py-2 text-xs font-semibold text-primary shadow-lg"></p>

    <p class="absolute inset-0 hidden items-center justify-center text-center text-sm text-white/60 group-data-failed:flex">
        The campus map couldn't load on this device.
    </p>

    <figcaption class="sr-only">
        Building heights are based on floor counts. Tap a building to see its name.
    </figcaption>
</figure>
