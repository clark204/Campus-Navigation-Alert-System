{{-- One place's QR code (Admin\QrCodeController): the "QR code" button on Admin > Rooms and Admin > Buildings.
     A card to print (A4, cut along the dashed edge) or save as a PNG: CNAS and TCC logos, the code, the name students
     know, where it is, and "Scan to start directions from here". A building with several doors gets a door picker;
     resources/js/qr-print.js selects its main entrance, switches doors, prints and draws the PNG. --}}
<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">

<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex">
    <title>QR code · {{ $name }} · TCC NAV</title>
    @include('partials.app-head')
    @vite(['resources/css/app.css', 'resources/js/app.js'])
    <style>
        @page { size: A4; margin: 12mm; }
        .qr-face { width: 95mm; height: 90mm; print-color-adjust: exact; -webkit-print-color-adjust: exact; }
        .qr-face svg { width: 100%; height: 100%; display: block; }
        @media screen and (min-width: 520px) { .qr-face { zoom: 1.35; } }
    </style>
</head>

<body class="min-h-svh bg-bg-light font-sans text-text-primary antialiased print:bg-white">
    <header class="border-b border-border bg-surface print:hidden">
        <div class="mx-auto flex max-w-3xl flex-wrap items-center justify-between gap-3 px-4 py-3">
            <a href="{{ $backUrl }}" class="inline-flex min-h-11 items-center gap-1.5 rounded-lg px-2 text-sm font-semibold text-primary transition hover:bg-bg-light">
                <svg class="size-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="m15 18-6-6 6-6" /></svg>
                Back to {{ $backLabel }}
            </a>
            @if ($cards)
                <div class="flex items-center gap-2">
                    <button type="button" data-qr-png
                        class="rounded-lg border border-border bg-surface px-4 py-2.5 text-sm font-semibold text-primary transition hover:border-secondary hover:bg-secondary/10 disabled:opacity-50">
                        Download PNG
                    </button>
                    <button type="button" data-qr-print-page
                        class="inline-flex items-center gap-2 rounded-lg bg-primary px-4 py-2.5 text-sm font-semibold text-white transition hover:bg-primary-hover">
                        <svg class="size-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M7 9V3h10v6M7 18H5a2 2 0 0 1-2-2v-5a2 2 0 0 1 2-2h14a2 2 0 0 1 2 2v5a2 2 0 0 1-2 2h-2M7 14h10v7H7z" /></svg>
                        Print
                    </button>
                </div>
            @endif
        </div>
    </header>

    <main data-qr-sheet data-map-url="{{ route('api.map') }}" class="mx-auto flex max-w-3xl flex-col items-center gap-6 px-4 py-8 print:block print:p-0">
        <div class="w-full max-w-xl text-center print:hidden">
            <h1 class="text-2xl font-semibold tracking-tight text-primary">QR code for {{ $name }}</h1>
            @if ($cards)
                <p class="mt-1 text-sm text-text-muted">Print it and post it {{ count($cards) > 1 ? 'at that door' : 'at the door' }}. Scanning it opens directions that start right there, on the website and in the CNAS app.</p>
            @endif
        </div>

        @if ($cards && ! $publicUrl)
            <div role="note" class="w-full max-w-xl rounded-xl border border-amber-300 bg-amber-50 px-4 py-3 text-sm text-amber-900 print:hidden">
                <p><strong class="font-semibold">Not ready for posting yet.</strong> This code opens {{ $appUrl }}, which phones on campus can’t reach. Set <code class="rounded bg-amber-100 px-1">APP_URL</code> to the site’s public https address first.</p>
            </div>
        @endif

        @if (! $cards)
            <div class="w-full max-w-xl rounded-2xl border border-border bg-surface p-6 text-center">
                <span class="mx-auto grid size-12 place-items-center rounded-full bg-amber-100 text-amber-700" aria-hidden="true">
                    <svg class="size-6" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round"><path d="M12 8v5m0 3.5h.01M12 3a9 9 0 1 0 0 18 9 9 0 0 0 0-18z" /></svg>
                </span>
                <h2 class="mt-3 font-semibold">This QR code isn’t ready yet</h2>
                <p class="mt-1 text-sm text-text-muted">{{ $reason }}</p>
                <a href="{{ $backUrl }}" class="mt-4 inline-flex rounded-lg border border-border px-4 py-2.5 text-sm font-semibold text-primary transition hover:bg-bg-light">Back to {{ $backLabel }}</a>
            </div>
        @else
            @if (count($cards) > 1)
                <fieldset class="w-full max-w-xl print:hidden">
                    <legend class="mb-2 text-sm font-semibold">Which door will you post it at?</legend>
                    <div data-qr-doors class="flex flex-wrap gap-2">
                        @foreach ($cards as ['code' => $code])
                            <label data-door-choice="{{ $code->from }}"
                                class="inline-flex cursor-pointer items-center gap-2 rounded-full border border-border bg-surface px-3.5 py-2 text-sm transition hover:border-secondary has-checked:border-primary has-checked:bg-primary has-checked:text-white">
                                <input type="radio" name="door" value="{{ $code->from }}" class="sr-only" @checked($loop->first)>
                                {{ Str::after($code->detail, 'Door of ') }}
                                <span data-main-badge hidden class="rounded-full bg-secondary px-1.5 py-0.5 text-[11px] font-semibold text-primary">Main entrance</span>
                            </label>
                        @endforeach
                    </div>
                </fieldset>
            @endif

            @foreach ($cards as ['code' => $code, 'svg' => $svg])
                <article data-qr-card data-door="{{ $code->from }}" data-file="qr-{{ Str::slug($code->title.' '.$code->detail) }}" @unless ($loop->first) hidden @endunless
                    class="rounded-sm bg-white shadow-md print:mx-auto print:shadow-none">
                    {{-- The printed card, 95 × 90 mm: cut along the dashed edge. --}}
                    <div class="qr-face flex flex-col items-center overflow-hidden border border-dashed border-text-muted/50 px-[6mm] pt-[3.5mm] pb-[2.5mm] text-center">
                        <div class="flex w-full items-center justify-between">
                            <span class="flex items-center gap-[1mm] text-left">
                                <img data-logo src="{{ asset('logo/nav-logo.svg') }}" alt="" class="-ml-[2mm] size-[13mm]">
                                <span class="-ml-[2mm] leading-tight">
                                    <span class="block text-[9pt] font-bold tracking-wide text-primary">TCC NAV</span>
                                    <span class="block text-[6.5pt] text-text-muted">Campus Navigation</span>
                                </span>
                            </span>
                            <img data-logo src="{{ asset('logo/tcc-seal.png') }}" alt="Tagoloan Community College" class="size-[11mm]">
                        </div>
                        <div data-qr-svg class="mt-[1mm] size-[42mm]" role="img" aria-label="QR code that starts directions from {{ $code->title }}">{!! $svg !!}</div>
                        <h2 data-qr-title class="mt-[1mm] w-full truncate text-[16pt] leading-tight font-bold text-primary">{{ $code->title }}</h2>
                        <p data-qr-detail class="w-full truncate text-[10pt] text-text-primary">{{ $code->detail }}</p>
                        <p class="mt-[2mm] rounded-full bg-secondary/20 px-[3.5mm] py-[1mm] text-[9pt] font-semibold text-primary">Scan to start directions from here</p>
                        <p class="mt-auto w-full truncate font-mono text-[6.5pt] text-text-muted">{{ $code->url }}</p>
                    </div>
                </article>
            @endforeach

            <p class="text-xs text-text-muted print:hidden">Prints at its real size (95 × 90 mm). Print at 100%, then cut along the dashed line.</p>
        @endif
    </main>
</body>

</html>
