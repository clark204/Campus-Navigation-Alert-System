@php
    // Replace the # values with the college's actual page URLs.
    $quickLinks = [
        'Admissions' => '#',
        'Academics' => '#',
        'Sustainable Development Goals' => '#',
        'News & updates' => '#',
        'Events' => '#',
        'Contact' => '#',
    ];
    $externalLinks = ['CHED' => '#', 'Tagoloan LGU' => '#'];
@endphp

<footer class="relative isolate overflow-hidden bg-primary-hover text-white/80">
    <img src="{{ asset('images/footer.jpg') }}" alt="" loading="lazy"
        class="absolute inset-0 -z-20 h-full w-full object-cover object-center">
    <div class="absolute inset-0 -z-10 bg-[#210c13]/90"></div>

    <div class="mx-auto grid max-w-7xl gap-9 px-5 py-12 sm:grid-cols-2 sm:px-8 sm:py-16 lg:grid-cols-[1.2fr_1.15fr_1.15fr_.85fr] lg:gap-10">
        <div>
            <h2 class="text-base font-bold text-white">Tagoloan Community College</h2>
            <p class="mt-3 text-sm leading-6">A public higher education institution serving Tagoloan, Misamis Oriental — committed to accessible, relevant programs for students and communities.</p>
        </div>

        <nav aria-label="Quick links">
            <h2 class="footer-heading">QUICK LINKS</h2>
            <ul class="grid gap-2 text-sm">
                @foreach ($quickLinks as $label => $url)
                    <li><a href="{{ $url }}" class="footer-link">{{ $label }}</a></li>
                @endforeach
            </ul>
        </nav>

        <div>
            <h2 class="footer-heading">VISIT & SUPPORT</h2>
            <address class="text-sm leading-6 not-italic">Tagoloan, Misamis Oriental,<br>Philippines</address>
            <a href="#" class="footer-link mt-3 inline-block text-sm">Contact the college →</a>
            <p class="mt-4 text-xs leading-5 text-white/50">We look forward to welcoming you to campus.</p>
        </div>

        <nav aria-label="External references">
            <h2 class="footer-heading">EXTERNAL REFERENCES</h2>
            <ul class="grid gap-2 text-sm">
                @foreach ($externalLinks as $label => $url)
                    <li><a href="{{ $url }}" class="footer-link">{{ $label }}</a></li>
                @endforeach
            </ul>
        </nav>
    </div>

    <div class="border-t border-white/15">
        <div class="mx-auto flex max-w-7xl flex-col gap-4 px-5 py-5 text-xs text-white/55 sm:flex-row sm:items-center sm:justify-between sm:px-8">
            <p>© {{ date('Y') }} Tagoloan Community College. All rights reserved.</p>
            <div class="flex items-center gap-6">
                <a href="#" class="hover:text-white">Privacy & transparency</a>
                <a href="#top" aria-label="Back to top"
                    class="flex size-10 items-center justify-center rounded-full border border-secondary/50 bg-primary text-lg text-white hover:bg-primary-hover">↑</a>
            </div>
        </div>
    </div>
</footer>
