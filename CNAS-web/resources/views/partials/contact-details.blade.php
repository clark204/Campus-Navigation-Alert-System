{{-- CNAS administrator contact details from config/cnas.php, one line each with an icon.
     Used by the site footer (pass 'onDark' => true) and the portal/admin help footer. --}}
@php
    $contact = config('cnas.contact');
    $phoneHref = 'tel:'.preg_replace('/[^\d+]/', '', $contact['phone']);
    $onDark ??= false;
    $iconClass = $onDark ? 'text-secondary' : 'text-secondary-hover';
    $linkClass = $onDark
        ? 'text-white/85 underline-offset-4 transition hover:text-white hover:underline'
        : 'font-semibold text-primary underline-offset-4 hover:underline';
    $textClass = $onDark ? 'text-white/70' : 'text-text-muted';
@endphp
<ul class="grid gap-3 text-sm">
    <li class="flex min-w-0 items-start gap-3">
        <svg class="mt-0.5 size-4 shrink-0 {{ $iconClass }}" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"
            stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
            <rect x="3" y="5" width="18" height="14" rx="2" />
            <path d="m3.5 6.5 8.5 6.5 8.5-6.5" />
        </svg>
        <a href="mailto:{{ $contact['email'] }}" class="min-w-0 break-words {{ $linkClass }}">
            <span class="sr-only">Email: </span>{{ $contact['email'] }}
        </a>
    </li>
    <li class="flex items-start gap-3">
        <svg class="mt-0.5 size-4 shrink-0 {{ $iconClass }}" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"
            stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
            <path d="M5 4h4l2 5-2.5 1.5a11 11 0 0 0 5 5L15 13l5 2v4a2 2 0 0 1-2 2A16 16 0 0 1 3 6a2 2 0 0 1 2-2" />
        </svg>
        <a href="{{ $phoneHref }}" class="{{ $linkClass }}"><span class="sr-only">Phone: </span>{{ $contact['phone'] }}</a>
    </li>
    <li class="flex items-start gap-3">
        <svg class="mt-0.5 size-4 shrink-0 {{ $iconClass }}" viewBox="0 0 24 24" fill="currentColor" aria-hidden="true">
            <path d="M14 8.5V7c0-.8.2-1.3 1.4-1.3H17V2.6A21 21 0 0 0 14.6 2.5C12.2 2.5 10.6 4 10.6 6.6v1.9H8v3.4h2.6V21.5H14v-9.6h2.6l.4-3.4z" />
        </svg>
        <a href="{{ $contact['facebook'] }}" target="_blank" rel="noopener noreferrer" class="{{ $linkClass }}">
            Tagoloan Community College on Facebook<span class="sr-only"> (opens in a new tab)</span>
        </a>
    </li>
    <li class="flex items-start gap-3">
        <svg class="mt-0.5 size-4 shrink-0 {{ $iconClass }}" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"
            stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
            <path d="M12 21s-7-6.2-7-11.5a7 7 0 0 1 14 0C19 14.8 12 21 12 21z" />
            <circle cx="12" cy="9.5" r="2.5" />
        </svg>
        <address class="not-italic {{ $textClass }}">Tagoloan, Misamis Oriental, Philippines</address>
    </li>
</ul>
