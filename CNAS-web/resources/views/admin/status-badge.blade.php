{{-- A System status badge: an icon and a word, never color alone. $ok: "OK" or "Needs attention".
     $hidden: rendered but hidden (the live push subscriber check renders both and switches between them). --}}
<span data-badge="{{ $ok ? 'ok' : 'attention' }}" @if ($hidden ?? false) hidden @endif @class([
    'inline-flex items-center gap-1.5 rounded-full border px-2.5 py-0.5 text-xs font-semibold',
    'border-emerald-200 bg-emerald-50 text-emerald-800' => $ok,
    'border-red-200 bg-red-50 text-red-800' => ! $ok,
])>
    <svg class="size-3.5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round"
        stroke-linejoin="round" aria-hidden="true">
        <path d="{{ $ok ? 'm8.5 12.5 2.5 2.5 4.5-5M21 12a9 9 0 1 1-18 0 9 9 0 0 1 18 0z' : 'M12 3 2 20h20zM12 10v4m0 3h.01' }}" />
    </svg>
    {{ $ok ? 'OK' : 'Needs attention' }}
</span>
