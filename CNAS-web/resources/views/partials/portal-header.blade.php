{{-- Portal top bar: logo, section tabs from md (the bottom tab bar covers phones) and the signed-in person (placeholder until auth). --}}
<header class="sticky top-0 z-30 border-b border-white/10 bg-primary text-white">
    <div class="mx-auto flex h-16 max-w-6xl items-center gap-6 px-4 sm:px-8 xl:px-10">
        <a href="{{ route("{$role->value}.dashboard") }}" class="flex shrink-0 items-center gap-1" aria-label="TCC NAV home">
            <img src="{{ asset('logo/nav-logo.svg') }}" alt="" class="-ml-2 size-14">
            <span class="-ml-2 flex flex-col leading-tight">
                <span class="font-bold tracking-wide text-secondary">TCC NAV</span>
                <span class="text-xs text-white/60">{{ ucfirst($role->value) }}</span>
            </span>
        </a>

        <nav aria-label="Portal navigation" class="max-md:hidden">
            <ul class="flex items-center gap-1">
                @foreach ($tabs as $tab)
                    @php($active = request()->routeIs($tab['route']))
                    <li>
                        <a href="{{ Route::has($tab['route']) ? route($tab['route']) : '#' }}"
                            @if ($active) aria-current="page" @endif
                            @class([
                                'flex items-center gap-2 rounded-lg px-3 py-2 text-sm transition',
                                'bg-white/10 font-semibold text-white shadow-[inset_0_-2px_0_var(--color-secondary)]' => $active,
                                'text-white/75 hover:bg-white/5 hover:text-white' => ! $active,
                            ])>
                            <svg class="size-5 shrink-0" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7"
                                stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                                <path d="{{ $tab['icon'] }}" />
                            </svg>
                            {{ $tab['label'] }}
                        </a>
                    </li>
                @endforeach
            </ul>
        </nav>

        <div class="ml-auto flex items-center gap-3">
            <span class="flex flex-col text-right leading-tight max-sm:hidden">
                <span class="text-sm font-semibold">{{ $person['name'] }}</span>
                <span class="text-xs text-white/60">{{ ucfirst($role->value) }}</span>
            </span>
            <span class="flex size-9 items-center justify-center rounded-full bg-secondary text-sm font-semibold text-primary" aria-hidden="true">{{ $person['initials'] }}</span>
        </div>
    </div>
</header>
