{{-- Breadcrumb trail in the top bar of the admin console and the portal (partials/app-topbar): home › the sidebar item
     the page belongs to › the page's own steps. Needs $groups (the sidebar's; its first item is home).
     A page deeper than its sidebar item sets its steps in a PHP block at the top of its view, before the sections
     (the variable reaches the layout from there), e.g. on Admin > Accounts > a person > Edit details:
         $breadcrumbs = [['label' => $account->full_name, 'url' => route('admin.accounts.show', $account)], ['label' => 'Edit details']];
     The last step is the current page and is never a link. A page outside the sidebar (Search, Help & contact) shows
     its 'heading' section instead. Below sm only the current page shows, after a back arrow to the step before it. --}}
@php
    $navItems = collect($groups)->flatten(1);
    $home = $navItems->first();
    $section = $navItems->first(fn (array $item): bool => request()->routeIs(...(array) ($item['active'] ?? $item['route'])));
    $heading = trim($__env->yieldContent('heading'));

    $trail = [['label' => $home['label'], 'url' => route($home['route'])]];
    if ($section && $section !== $home) {
        $trail[] = ['label' => $section['label'], 'url' => route($section['route'])];
    } elseif (! $section && $heading !== '') {
        $trail[] = ['label' => $heading];
    }
    $trail = [...$trail, ...($breadcrumbs ?? [])];
    unset($trail[array_key_last($trail)]['url']);
    $parent = $trail[count($trail) - 2] ?? null;
@endphp

<nav aria-label="Breadcrumb" class="flex min-w-0 items-center gap-1">
    @if (isset($parent['url']))
        <a href="{{ $parent['url'] }}" aria-label="Back to {{ $parent['label'] }}"
            class="shrink-0 rounded-lg p-1.5 text-text-muted transition hover:bg-bg-light hover:text-primary sm:hidden">
            <svg class="size-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                <path d="m15 6-6 6 6 6" />
            </svg>
        </a>
    @endif

    <ol class="flex min-w-0 items-center gap-1.5 text-sm">
        @foreach ($trail as $step)
            <li @class(['flex items-center gap-1.5', 'min-w-0' => $loop->last, 'shrink-0 max-sm:hidden' => ! $loop->last])>
                @if (! $loop->first)
                    <svg class="size-4 shrink-0 text-text-muted/50 max-sm:hidden" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                        <path d="m9 6 6 6-6 6" />
                    </svg>
                @endif
                @if (isset($step['url']))
                    <a href="{{ $step['url'] }}" class="block max-w-48 truncate text-text-muted underline-offset-4 transition hover:text-primary hover:underline">{{ $step['label'] }}</a>
                @else
                    <span aria-current="page" class="truncate text-base font-semibold text-primary">{{ $step['label'] }}</span>
                @endif
            </li>
        @endforeach
    </ol>
</nav>
