{{-- Admin search results (SearchController@index): every group with up to AdminSearch::PAGE_LIMIT results. Opened by
     Enter in the top bar search, or by the search button on small screens without JavaScript. --}}
@extends('layout.admin-layout')
@use('App\Services\AdminSearch')

@section('title', $query !== '' ? "Search: {$query}" : 'Search')
@section('heading', 'Search')

@section('content.admin')
    <div class="flex flex-col gap-6">
        <div>
            <h1 class="text-2xl font-semibold tracking-tight text-primary sm:text-3xl">Search</h1>
            <p class="mt-1 text-sm text-text-muted">Buildings, rooms, 360° photos, people and admin pages.</p>
        </div>

        <form method="GET" action="{{ route('admin.search') }}" role="search" class="flex flex-col gap-3 sm:flex-row">
            <label for="search-page-q" class="sr-only">Search the admin console</label>
            <input id="search-page-q" name="q" type="search" value="{{ $query }}" maxlength="{{ AdminSearch::MAX_LENGTH }}" autocomplete="off"
                placeholder="Search buildings, rooms, people" class="login-input sm:flex-1">
            <button type="submit" class="rounded-lg bg-primary px-5 py-2.5 text-sm font-semibold text-white transition hover:bg-primary-hover">Search</button>
        </form>

        @if ($tooShort)
            <p class="rounded-2xl border border-border bg-surface px-5 py-4 text-sm text-text-muted">Type at least {{ AdminSearch::MIN_LENGTH }} letters to search.</p>
        @elseif ($groups === [])
            <div class="rounded-2xl border border-border bg-surface px-5 py-4 text-sm">
                <p class="font-semibold">No buildings, rooms, photos, people or pages match “{{ $query }}”.</p>
                <p class="mt-1 text-text-muted">Check the spelling, or try part of a name, a room or building code (like r5 or b1), or a school ID.</p>
            </div>
        @else
            @foreach ($groups as $group)
                <section aria-labelledby="results-{{ $loop->index }}" class="overflow-hidden rounded-2xl border border-border bg-surface">
                    <div class="flex items-baseline justify-between gap-4 px-5 pt-4 pb-3">
                        <h2 id="results-{{ $loop->index }}" class="font-semibold">{{ $group['label'] }}</h2>
                        <p class="text-xs text-text-muted tabular-nums">
                            @if (count($group['results']) >= AdminSearch::PAGE_LIMIT)
                                First {{ AdminSearch::PAGE_LIMIT }}. Type more to narrow it down.
                            @else
                                {{ count($group['results']) }} {{ Str::plural('result', count($group['results'])) }}
                            @endif
                        </p>
                    </div>
                    <ul class="divide-y divide-border border-t border-border">
                        @foreach ($group['results'] as $result)
                            <li>
                                <a href="{{ $result['url'] }}" class="flex items-center justify-between gap-4 px-5 py-3 transition hover:bg-bg-light">
                                    <span class="min-w-0">
                                        <span class="block text-sm font-semibold break-words">{{ $result['title'] }}</span>
                                        <span class="block text-xs break-words text-text-muted">{{ $result['detail'] }}</span>
                                    </span>
                                    <svg class="size-4 shrink-0 text-text-muted" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"
                                        stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                                        <path d="m9 6 6 6-6 6" />
                                    </svg>
                                </a>
                            </li>
                        @endforeach
                    </ul>
                </section>
            @endforeach
        @endif
    </div>
@endsection
