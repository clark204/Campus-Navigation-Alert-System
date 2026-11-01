{{-- Help footer for the portal and admin layouts, which don't use the site footer. Same #contact anchor and the
     same contact details (config/cnas.php) as the site footer. --}}
<footer id="contact" aria-labelledby="contact-heading" class="mt-10 scroll-mt-20 border-t border-border pt-6">
    <div class="grid gap-5 rounded-2xl border border-border bg-surface p-5 sm:p-6 lg:grid-cols-[1fr_1.2fr] lg:items-center">
        <div>
            <p class="text-[10px] font-bold tracking-[.2em] text-secondary-hover">NEED HELP?</p>
            <h2 id="contact-heading" class="mt-1.5 text-lg font-semibold text-primary">Contact the CNAS administrator</h2>
            <p class="mt-1 text-sm leading-6 text-text-muted">Questions about your account or the campus map? Get in touch.</p>
        </div>
        <div>
            @include('partials.contact-details')
        </div>
    </div>
</footer>
