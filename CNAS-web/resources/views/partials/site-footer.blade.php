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

            {{-- Goes to Admin > Concerns (ConcernController); signed-in users send from the portal's Help & contact. --}}
            @php($concernErrors = isset($errors) ? $errors->getBag('concern') : new \Illuminate\Support\MessageBag)
            <form id="feedback" method="POST" action="{{ route('concerns.store') }}" novalidate data-submit-once class="mt-4 grid scroll-mt-24 gap-3">
                @csrf
                @if (session('concern_status'))
                    <p role="status" class="rounded-xl border border-emerald-300/40 bg-emerald-400/15 px-4 py-3 text-sm text-white">{{ session('concern_status') }}</p>
                @endif
                <div>
                    <label for="feedback-email" class="mb-1.5 block text-xs font-semibold text-white/80">Email</label>
                    <input id="feedback-email" name="email" type="email" required maxlength="255" autocomplete="email" autocapitalize="none"
                        spellcheck="false" placeholder="example@gmail.com" value="{{ old('email', auth()->user()?->email) }}"
                        class="footer-input" @if ($concernErrors->has('email')) aria-invalid="true" aria-describedby="feedback-email-error" @endif>
                    @if ($concernErrors->has('email'))
                        <p id="feedback-email-error" class="mt-1.5 text-xs font-medium text-[#ffd6d6]">{{ $concernErrors->first('email') }}</p>
                    @endif
                </div>
                <div>
                    <label for="feedback-topic" class="mb-1.5 block text-xs font-semibold text-white/80">About</label>
                    <select id="feedback-topic" name="topic" required class="footer-input [&>option]:text-text-primary"
                        @if ($concernErrors->has('topic')) aria-invalid="true" aria-describedby="feedback-topic-error" @endif>
                        <option value="" @selected(! old('topic', request('topic')))>Choose a topic</option>
                        @foreach (\App\Enums\ConcernTopic::cases() as $topic)
                            <option value="{{ $topic->value }}" @selected(old('topic', request('topic')) === $topic->value)>{{ $topic->label() }}</option>
                        @endforeach
                    </select>
                    @if ($concernErrors->has('topic'))
                        <p id="feedback-topic-error" class="mt-1.5 text-xs font-medium text-[#ffd6d6]">{{ $concernErrors->first('topic') }}</p>
                    @endif
                </div>
                <div>
                    <label for="feedback-message" class="mb-1.5 block text-xs font-semibold text-white/80">Message</label>
                    <textarea id="feedback-message" name="message" rows="3" required minlength="10" maxlength="{{ \App\Http\Requests\StoreConcernRequest::MESSAGE_MAX }}"
                        placeholder="Tell us what's on your mind" class="footer-input resize-y"
                        @if ($concernErrors->has('message')) aria-invalid="true" aria-describedby="feedback-message-error" @endif>{{ old('message') }}</textarea>
                    @if ($concernErrors->has('message'))
                        <p id="feedback-message-error" class="mt-1.5 text-xs font-medium text-[#ffd6d6]">{{ $concernErrors->first('message') }}</p>
                    @endif
                </div>
                <button type="submit"
                    class="min-h-11 justify-self-start rounded-xl bg-secondary px-6 text-sm font-bold text-primary-hover shadow-lg transition hover:bg-secondary-hover hover:text-white active:translate-y-px disabled:opacity-60">
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
