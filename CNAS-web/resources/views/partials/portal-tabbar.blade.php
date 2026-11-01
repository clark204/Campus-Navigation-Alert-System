{{-- Phone tab bar (below md). Same tabs as the top bar; links without a route yet fall back to '#'. --}}
<nav aria-label="Portal navigation"
    class="fixed inset-x-0 bottom-0 z-30 border-t border-border bg-surface/95 pb-[env(safe-area-inset-bottom)] backdrop-blur md:hidden">
    <ul class="flex">
        @foreach ($tabs as $tab)
            @php($active = request()->routeIs($tab['route']))
            <li class="flex-1">
                <a href="{{ Route::has($tab['route']) ? route($tab['route']) : '#' }}"
                    @if ($active) aria-current="page" @endif
                    @class([
                        'relative flex flex-col items-center gap-1 pt-2.5 pb-2 text-xs transition',
                        'font-semibold text-primary before:absolute before:inset-x-6 before:top-0 before:h-0.5 before:rounded-full before:bg-secondary' => $active,
                        'text-text-muted hover:text-primary' => ! $active,
                    ])>
                    <svg class="size-6" viewBox="0 0 24 24" fill="{{ $active ? 'currentColor' : 'none' }}" fill-opacity="0.12"
                        stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                        <path d="{{ $tab['icon'] }}" />
                    </svg>
                    {{ $tab['label'] }}
                </a>
            </li>
        @endforeach
    </ul>
</nav>
