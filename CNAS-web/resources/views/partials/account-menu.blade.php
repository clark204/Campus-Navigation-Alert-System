{{-- The signed-in user's menu in the top bar: who is signed in, links to their profile and settings, and Sign out.
     A <details> so it opens without JavaScript; app.js closes it on Escape, on a click outside and after a link.
     Needs $user; $class: extra classes (e.g. ml-auto). Admins get their settings; students and faculty their
     portal's settings tabs, Saved places and Help & contact. --}}
@php
    $icons = [
        'profile' => 'M12 12a4 4 0 1 0 0-8 4 4 0 0 0 0 8zm-7 9a7 7 0 0 1 14 0',
        'key' => 'M14.5 9.5a4.5 4.5 0 1 1-1.3-3.2 4.5 4.5 0 0 1 1.3 3.2zm-1.3 3.2L20 19.5M17.5 17l2-2',
        'bell' => 'M6 9a6 6 0 1 1 12 0c0 6 2.5 7.5 2.5 7.5h-17S6 15 6 9zM10 20a2.2 2.2 0 0 0 4 0',
        'bookmark' => 'M6 4h12v17l-6-4-6 4z',
        'settings' => 'M4 6h9m4 0h3M4 12h3m4 0h9M4 18h11m4 0h1M15 4v4M9 10v4m8 2v4',
        'help' => 'M12 21a9 9 0 1 0 0-18 9 9 0 0 0 0 18zM9.5 9.5a2.5 2.5 0 1 1 3.4 2.3c-.6.3-.9.8-.9 1.4v.3M12 17h.01',
    ];
    $items = $user->isAdmin()
        ? [
            ['label' => 'My account', 'href' => $user->settingsUrl('my-account'), 'icon' => 'key'],
            ['label' => 'Settings', 'href' => $user->settingsUrl(), 'icon' => 'settings'],
        ]
        : [
            ['label' => 'Profile', 'href' => $user->settingsUrl('profile'), 'icon' => 'profile'],
            ['label' => 'Email & password', 'href' => $user->settingsUrl('my-account'), 'icon' => 'key'],
            ['label' => 'Emergency alerts', 'href' => $user->settingsUrl('alerts'), 'icon' => 'bell'],
            ['label' => 'Saved places', 'href' => route($user->role->portal()->value.'.saved'), 'icon' => 'bookmark'],
            ['label' => 'Help & contact', 'href' => route($user->role->portal()->value.'.help'), 'icon' => 'help'],
        ];
    $detail = implode(' · ', array_filter([$user->role->label(), $user->school_id]));
@endphp
<details data-account-menu class="group/account relative {{ $class ?? '' }}">
    <summary aria-label="Account menu for {{ $user->full_name }}"
        class="flex cursor-pointer list-none items-center gap-3 rounded-xl py-1 pr-2 pl-1 transition select-none hover:bg-bg-light focus-visible:ring-4 focus-visible:ring-secondary/40 focus-visible:outline-none group-open/account:bg-bg-light [&::-webkit-details-marker]:hidden">
        <span class="flex size-9 items-center justify-center rounded-full bg-secondary/20 text-sm font-semibold text-primary ring-2 ring-transparent transition group-open/account:ring-secondary" aria-hidden="true">{{ $user->initials }}</span>
        <span class="flex flex-col leading-tight max-sm:hidden">
            <span class="text-sm font-semibold">{{ $user->full_name }}</span>
            <span class="text-xs text-text-muted">{{ $user->role->label() }}</span>
        </span>
        <svg class="size-4 text-text-muted transition group-open/account:rotate-180 max-sm:hidden" viewBox="0 0 24 24" fill="none" stroke="currentColor"
            stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
            <path d="m6 9 6 6 6-6" />
        </svg>
    </summary>

    <div class="absolute right-0 z-30 mt-2 w-[min(18rem,calc(100vw-2rem))] overflow-hidden rounded-2xl border border-border bg-surface shadow-lg">
        <div class="flex items-center gap-3 border-b border-border bg-primary px-4 py-4 text-white shadow-[inset_0_-3px_0_var(--color-secondary)]">
            <span class="flex size-11 shrink-0 items-center justify-center rounded-full bg-secondary font-semibold text-primary-hover" aria-hidden="true">{{ $user->initials }}</span>
            <span class="min-w-0">
                <span class="block truncate font-semibold">{{ $user->full_name }}</span>
                <span class="block truncate text-xs text-white/75">{{ $user->email }}</span>
                <span class="block truncate text-xs text-white/60 tabular-nums">{{ $detail }}</span>
            </span>
        </div>

        <nav aria-label="Account" class="p-2">
            <ul class="flex flex-col gap-0.5">
                @foreach ($items as $item)
                    <li>
                        <a href="{{ $item['href'] }}" data-account-menu-link
                            class="flex min-h-11 items-center gap-3 rounded-lg px-3 text-sm font-medium transition hover:bg-bg-light hover:text-primary focus-visible:ring-4 focus-visible:ring-secondary/40 focus-visible:outline-none">
                            <svg class="size-5 shrink-0 text-text-muted" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7"
                                stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                                <path d="{{ $icons[$item['icon']] }}" />
                            </svg>
                            {{ $item['label'] }}
                        </a>
                    </li>
                @endforeach
            </ul>
        </nav>

        <form method="POST" action="{{ route('logout') }}" class="border-t border-border p-2">
            @csrf
            <button type="submit"
                class="flex min-h-11 w-full items-center gap-3 rounded-lg px-3 text-sm font-medium text-red-700 transition hover:bg-red-50 focus-visible:ring-4 focus-visible:ring-secondary/40 focus-visible:outline-none">
                <svg class="size-5 shrink-0" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round"
                    stroke-linejoin="round" aria-hidden="true">
                    <path d="M15 4h3a2 2 0 0 1 2 2v12a2 2 0 0 1-2 2h-3M10 17l5-5-5-5m5 5H4" />
                </svg>
                Sign out
            </button>
        </form>
    </div>
</details>
