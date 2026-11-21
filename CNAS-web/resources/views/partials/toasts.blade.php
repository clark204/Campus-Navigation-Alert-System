{{-- Toast notifications, in every layout that shows flashed messages (portal, admin, and the index layout's sign-in and
     verify pages). Every page's flash messages show here instead of as a box on the page:
       - `status` (e.g. "Saved.") and `toast` (a string, or ['title' => …, 'body' => …], e.g. "Email verified"):
         a success toast that closes by itself when its gold timer bar runs out (paused on hover or focus);
       - `warning` (e.g. "Saved, but the photo has no GPS position"): stays until closed, since it asks for attention.
     Emergency alerts are not toasts: they have their own banner and takeover (partials/alerts).
     Each toast is popover="manual" (never light-dismissed); resources/js/toasts.js opens and stacks them, announces
     them to screen readers and steps aside if the emergency takeover opens. Form errors stay next to their fields. --}}
@php
    $toasts = [];
    foreach (['toast' => 'success', 'status' => 'success', 'warning' => 'warning'] as $key => $tone) {
        $flash = session($key);
        if (filled($flash)) {
            $toasts[] = ['tone' => $tone] + (is_array($flash) ? $flash : ['title' => $flash]);
        }
    }
@endphp
<div role="region" aria-label="Notifications" data-toast-region>
    <p class="sr-only" aria-live="polite" data-toast-live></p>

    @foreach ($toasts as $item)
        @php
            // Longer messages stay a little longer: 6 s, plus 1 s for every 15 characters past 60, up to 12 s.
            $length = mb_strlen($item['title'].($item['body'] ?? ''));
            $seconds = min(12, 6 + intdiv(max(0, $length - 60), 15));
        @endphp
        <div popover="manual" class="toast" data-toast data-tone="{{ $item['tone'] }}">
            @if ($item['tone'] === 'warning')
                <span class="grid size-9 shrink-0 place-items-center rounded-full bg-secondary/20 text-primary" aria-hidden="true">
                    <svg class="size-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round">
                        <path d="M10.3 4.2 2.6 17.5A2 2 0 0 0 4.3 20.5h15.4a2 2 0 0 0 1.7-3L13.7 4.2a2 2 0 0 0-3.4 0Z" />
                        <path d="M12 9.5v4M12 17v.01" />
                    </svg>
                </span>
            @else
                <span class="grid size-9 shrink-0 place-items-center rounded-full bg-safe-tint text-safe" aria-hidden="true">
                    <svg class="size-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round" stroke-linejoin="round">
                        <path d="m5 12.5 4.5 4.5L19 7.5" />
                    </svg>
                </span>
            @endif
            <div class="min-w-0 pt-0.5 [overflow-wrap:anywhere]" data-toast-message>
                <p class="text-sm font-semibold text-text-primary">{{ $item['title'] }}</p>
                @if (! empty($item['body']))
                    <p class="mt-0.5 text-sm text-text-muted">{{ $item['body'] }}</p>
                @endif
            </div>
            <button type="button" data-toast-close aria-label="Dismiss notification"
                class="-mt-1 -mr-1 grid size-9 shrink-0 place-items-center rounded-lg text-text-muted transition hover:bg-bg-light hover:text-primary">
                <svg class="size-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" aria-hidden="true">
                    <path d="M6 6l12 12M18 6 6 18" />
                </svg>
            </button>
            @if ($item['tone'] === 'success')
                <span class="toast-timer" style="--toast-duration: {{ $seconds }}s" data-toast-timer aria-hidden="true"></span>
            @endif
        </div>
    @endforeach
</div>
