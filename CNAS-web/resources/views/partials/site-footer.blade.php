{{-- id="contact": linked as #contact from pages that use this footer (e.g. the duplicate student ID message). --}}
<footer id="contact" class="relative isolate overflow-hidden bg-primary-hover text-white/80">
    <img src="{{ asset('images/footer.jpg') }}" alt="" loading="lazy"
        class="absolute inset-0 -z-20 h-full w-full object-cover object-center">
    <div class="absolute inset-0 -z-10 bg-[#210c13]/90"></div>

    <div class="mx-auto grid max-w-7xl gap-10 px-5 py-12 sm:grid-cols-2 sm:px-8 sm:py-16 lg:grid-cols-[minmax(0,1fr)_minmax(0,1fr)_minmax(0,22rem)] lg:gap-12">
        <div class="max-w-md">
            <h2 class="text-base font-bold text-white">Tagoloan Community College</h2>
            <p class="mt-3 text-sm leading-6">A public higher education institution serving Tagoloan, Misamis Oriental — committed to accessible, relevant programs for students and communities.</p>
        </div>

        <section aria-labelledby="contact-heading">
            <h2 id="contact-heading" class="text-base font-bold text-white">Contact the CNAS administrator</h2>
            <p class="mt-3 text-sm leading-6">Problems signing up, or someone already registered with your student ID? Get in touch.</p>
            <div class="mt-4">
                @include('partials.contact-details', ['onDark' => true])
            </div>
        </section>

        <section aria-labelledby="feedback-heading" class="sm:col-span-2 sm:max-w-md lg:col-span-1 lg:max-w-none">
            <h2 id="feedback-heading" class="text-base font-bold text-white">Concerns, suggestions &amp; feedback</h2>

            {{-- Frontend only for now: sending the message by email comes later (route + mail). Until then the form does nothing. --}}
            <form id="feedback" class="mt-4 grid gap-3" onsubmit="event.preventDefault()">
                <div>
                    <label for="feedback-email" class="mb-1.5 block text-xs font-semibold text-white/80">Email</label>
                    <input id="feedback-email" name="email" type="email" required autocomplete="email" autocapitalize="none"
                        spellcheck="false" placeholder="example@gmail.com" value="{{ auth()->user()?->email }}"
                        class="footer-input">
                </div>
                <div>
                    <label for="feedback-message" class="mb-1.5 block text-xs font-semibold text-white/80">Message</label>
                    <textarea id="feedback-message" name="message" rows="3" required
                        placeholder="Tell us what's on your mind" class="footer-input resize-y"></textarea>
                </div>
                <button type="submit"
                    class="min-h-11 justify-self-start rounded-xl bg-secondary px-6 text-sm font-bold text-primary-hover shadow-lg transition hover:bg-secondary-hover hover:text-white active:translate-y-px">
                    Send
                </button>
            </form>
        </section>
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
