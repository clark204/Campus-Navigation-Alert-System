{{-- Top bar shared by the admin console and the portal: menu toggle (below lg), breadcrumb trail (partials/app-breadcrumbs), optional search and the
     signed-in user's notification bell (partials/notification-bell) and account menu (partials/account-menu). Needs: $sidebarId and $search (id, label, placeholder, action: the results page, suggestUrl: JSON
     suggestions) or null. Signed-in pages only.
     Search: a GET form to the results page, so Enter works without JavaScript; resources/js/admin-search.js adds the
     suggestions list while typing. Below md the field is behind a search button (a link to the results page without
     JavaScript) and opens as a row under the bar. --}}
@php($user = request()->user())
<header class="sticky top-0 z-20 flex h-16 items-center gap-3 border-b border-border bg-surface/95 px-4 backdrop-blur sm:px-8 xl:px-10">
    <button type="button" data-sidebar-menu aria-controls="{{ $sidebarId }}" aria-expanded="false" aria-label="Open navigation"
        class="-ml-2 rounded-lg p-2 text-text-muted transition hover:bg-bg-light hover:text-primary lg:hidden">
        <svg class="size-6" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" aria-hidden="true">
            <path d="M4 7h16M4 12h16M4 17h16" />
        </svg>
    </button>

    @include('partials.app-breadcrumbs')

    @if ($search)
        <a href="{{ $search['action'] }}" data-search-toggle aria-controls="{{ $search['id'] }}-form" aria-label="Search"
            class="ml-auto rounded-lg p-2 text-text-muted transition hover:bg-bg-light hover:text-primary md:hidden">
            <svg class="size-6" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" aria-hidden="true">
                <path d="M11 18a7 7 0 1 0 0-14 7 7 0 0 0 0 14zm9 2-4-4" />
            </svg>
        </a>

        <form id="{{ $search['id'] }}-form" role="search" method="GET" action="{{ $search['action'] }}" data-admin-search
            data-suggest-url="{{ $search['suggestUrl'] }}"
            class="w-full max-md:absolute max-md:inset-x-0 max-md:top-full max-md:border-b max-md:border-border max-md:bg-surface max-md:px-4 max-md:py-3 max-md:shadow-sm max-md:not-data-open:hidden md:relative md:ml-auto md:max-w-xs">
            <label for="{{ $search['id'] }}" class="sr-only">{{ $search['label'] }}</label>
            <div class="relative">
                <svg class="pointer-events-none absolute top-1/2 left-3 size-4 -translate-y-1/2 text-text-muted" viewBox="0 0 24 24" fill="none"
                    stroke="currentColor" stroke-width="2" stroke-linecap="round" aria-hidden="true">
                    <path d="M11 18a7 7 0 1 0 0-14 7 7 0 0 0 0 14zm9 2-4-4" />
                </svg>
                <input id="{{ $search['id'] }}" name="q" type="search" placeholder="{{ $search['placeholder'] }}" maxlength="100" autocomplete="off"
                    value="{{ request()->routeIs('admin.search') ? request()->query('q') : '' }}"
                    role="combobox" aria-autocomplete="list" aria-expanded="false" aria-controls="{{ $search['id'] }}-results"
                    aria-describedby="{{ $search['id'] }}-hint"
                    class="peer w-full rounded-lg border border-border bg-bg-light py-2 pr-9 pl-9 text-sm placeholder:text-text-muted/80 focus:border-secondary focus:bg-white focus:ring-4 focus:ring-secondary/20 focus:outline-none">
                {{-- The "/" shortcut hint, only while the field is empty (the browser's clear button takes its place). --}}
                <kbd aria-hidden="true" class="pointer-events-none absolute top-1/2 right-2.5 hidden -translate-y-1/2 rounded border border-border px-1.5 text-xs text-text-muted md:peer-placeholder-shown:block">/</kbd>
            </div>
            <p id="{{ $search['id'] }}-hint" class="sr-only">Results appear as you type. Use the arrow keys to choose one, or press Enter to see all results. Press slash to come back here.</p>

            {{-- Filled by admin-search.js. --}}
            <div data-search-panel hidden
                class="absolute z-30 mt-2 overflow-hidden rounded-xl border border-border bg-surface shadow-lg max-md:inset-x-4 md:right-0 md:w-[28rem]">
                <p data-search-status class="px-4 py-3 text-sm text-text-muted empty:hidden"></p>
                <div id="{{ $search['id'] }}-results" data-search-list role="listbox" aria-label="Search results" class="max-h-[min(28rem,70svh)] overflow-y-auto empty:hidden"></div>
                <a data-search-all href="{{ $search['action'] }}" class="block border-t border-border px-4 py-2.5 text-sm font-semibold text-primary hover:bg-bg-light">See all results</a>
            </div>
            <p data-search-announce aria-live="polite" class="sr-only"></p>
        </form>
    @endif

    <div class="flex shrink-0 items-center gap-1 sm:gap-2 {{ $search ? '' : 'ml-auto' }}">
        @include('partials.notification-bell')
        @include('partials.account-menu', ['user' => $user])
    </div>
</header>
