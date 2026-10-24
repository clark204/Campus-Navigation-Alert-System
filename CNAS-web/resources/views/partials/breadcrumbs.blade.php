{{--
    Back button + breadcrumb trail for light-on-dark hero sections.
    $items: [['label' => 'Home', 'url' => route('landing')], ['label' => 'Sign in']] — the last item is the current page.
--}}
@php($parent = $items[count($items) - 2] ?? null)

<nav aria-label="Breadcrumb" class="flex items-center gap-4 text-xs text-white/65">
    {{-- @if ($parent)
        <a href="{{ $parent['url'] }}"
            class="inline-flex items-center gap-2 rounded-lg border border-white/15 bg-white/5 px-3 py-2 font-semibold text-white/85 backdrop-blur-sm transition hover:border-secondary/60 hover:bg-secondary/15 hover:text-white">
            <span aria-hidden="true">←</span> Back<span class="sr-only"> to {{ $parent['label'] }}</span>
        </a>
    @endif --}}

    <ol class="flex items-center gap-2 max-sm:hidden">
        @foreach ($items as $item)
            <li class="flex items-center gap-2">
                @if (! $loop->first)
                    <span aria-hidden="true" class="text-white/30">/</span>
                @endif
                @if ($loop->last)
                    <span aria-current="page" class="font-semibold text-[#ead59c]">{{ $item['label'] }}</span>
                @else
                    <a href="{{ $item['url'] }}" class="underline-offset-4 transition hover:text-white hover:underline">{{ $item['label'] }}</a>
                @endif
            </li>
        @endforeach
    </ol>
</nav>
