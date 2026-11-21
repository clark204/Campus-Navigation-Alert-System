{{-- The Accounts list, its "Showing … of …" line and the pages: the part the live search swaps in (accounts.js asks
     Admin\AccountController@index for it with the X-Accounts-Results header). Pages are plain links, so paging works
     without JavaScript; the buttons match the Buildings and Rooms lists. Needs $accounts (paginator), $search,
     $role, $trustedOnly. --}}
@php
    $joined = fn ($time) => $time === null ? 'Date unknown' : $time->timezone('Asia/Manila')->format($time->isCurrentYear() ? 'M j' : 'M j, Y');
    $clearSearchUrl = route('admin.accounts.index', array_filter(['role' => $role?->value, 'trusted' => $trustedOnly ? 1 : null]));

    // Every page when there are up to 7; otherwise the first, the last and the ones around the current, with gaps.
    $current = $accounts->currentPage();
    $last = $accounts->lastPage();
    $pages = $last <= 7
        ? range(1, $last)
        : collect([1, $current - 1, $current, $current + 1, $last])->filter(fn ($page) => $page >= 1 && $page <= $last)->unique()->sort()->values()->all();

    $button = 'inline-flex min-h-9 min-w-9 items-center justify-center rounded-lg border px-3 text-sm font-semibold transition';
    $idle = 'border-border text-primary hover:border-secondary hover:bg-secondary/10';
    $off = 'border-border text-text-muted/60';
@endphp

@if ($accounts->isEmpty())
    <div class="px-5 py-12 text-center">
        <p class="font-semibold">{{ $search !== '' ? 'No one matches “'.$search.'”.' : 'No accounts here yet.' }}</p>
        <p class="mt-1 text-sm text-text-muted">
            @if ($search !== '')
                Check the spelling, or <a href="{{ $clearSearchUrl }}" data-clear-search class="font-semibold text-primary underline underline-offset-4">clear the search</a>.
            @elseif ($trustedOnly)
                Open a faculty or staff account and turn on “Can send emergency alerts”.
            @else
                Create one with the button above.
            @endif
        </p>
    </div>
@else
    <ul id="accounts-list" class="divide-y divide-border">
        @foreach ($accounts as $account)
            @include('admin.account-row', ['account' => $account, 'joined' => $joined])
        @endforeach
    </ul>
@endif

<div class="flex flex-wrap items-center justify-between gap-3 border-t border-border px-5 py-3">
    <p data-results-summary class="text-sm text-text-muted tabular-nums">
        @if ($accounts->total() === 0)
            0 accounts
        @else
            Showing {{ $accounts->firstItem() }} to {{ $accounts->lastItem() }} of {{ $accounts->total() }}
        @endif
    </p>

    <nav aria-label="Pages" class="flex flex-wrap items-center gap-2">
        @if ($accounts->onFirstPage())
            <span class="{{ $button }} {{ $off }}" aria-disabled="true">Previous</span>
        @else
            <a href="{{ $accounts->previousPageUrl() }}" rel="prev" aria-label="Previous page" class="{{ $button }} {{ $idle }}">Previous</a>
        @endif

        @foreach ($pages as $page)
            @if (! $loop->first && $page - $pages[$loop->index - 1] > 1)
                <span class="px-1 text-sm text-text-muted" aria-hidden="true">…</span>
            @endif
            @if ($page === $current)
                <span aria-current="page" class="{{ $button }} border-primary bg-primary text-white">{{ $page }}</span>
            @else
                <a href="{{ $accounts->url($page) }}" aria-label="Page {{ $page }}" class="{{ $button }} {{ $idle }}">{{ $page }}</a>
            @endif
        @endforeach

        @if ($accounts->hasMorePages())
            <a href="{{ $accounts->nextPageUrl() }}" rel="next" aria-label="Next page" class="{{ $button }} {{ $idle }}">Next</a>
        @else
            <span class="{{ $button }} {{ $off }}" aria-disabled="true">Next</span>
        @endif
    </nav>
</div>
