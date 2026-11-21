{{-- Portal "Help & contact" (Portal\HelpController) for students and faculty: the concern form (to Admin > Concerns,
     ConcernController; the reply goes to the account's email), the user's own recent messages with their status, and
     the administrator's contact details (partials/contact-details, edited on /admin/settings) and emergency numbers.
     ?topic= preselects a topic (Settings → Profile links here with topic=account).
     Small screens: form, then contact, then your messages. Large screens: form and messages left, contact right. --}}
@extends('layout.portal-layout')
@use('App\Enums\ConcernTopic')
@use('App\Http\Requests\StoreConcernRequest')

@section('title', 'Help & contact')
@section('heading', 'Help & contact')

@php
    $concernErrors = $errors->getBag('concern');
    $chosenTopic = old('topic', ConcernTopic::tryFrom((string) request()->query('topic'))?->value);
    $fieldError = 'mt-1.5 text-xs text-red-600';
    $campusDate = fn ($time) => $time->timezone('Asia/Manila')->format('M j, Y');
@endphp

@section('content.portal')
    <div class="mx-auto flex max-w-5xl flex-col gap-6">
        <div>
            <h1 class="text-2xl font-semibold tracking-tight text-primary sm:text-3xl">Help &amp; contact</h1>
            <p class="mt-1 text-sm text-text-muted">A problem with your account, your schedule or the campus map? Tell the CNAS administrator here.</p>
        </div>

        <div class="grid items-start gap-6 lg:grid-cols-[minmax(0,1.5fr)_minmax(0,1fr)]">
            <section aria-labelledby="concern-heading" class="rounded-2xl border border-border bg-surface lg:col-start-1">
                <form method="POST" action="{{ route('concerns.store') }}" novalidate data-submit-once class="grid gap-5 p-5 sm:p-6">
                    @csrf
                    <div>
                        <h2 id="concern-heading" class="font-semibold">Send a concern or suggestion</h2>
                        <p class="mt-0.5 text-sm text-text-muted">The administrator replies to <span class="font-medium text-text-primary">{{ $user->email }}</span>.</p>
                    </div>

                    @if (session('concern_status'))
                        <p role="status" class="flex items-start gap-2 rounded-xl border border-emerald-200 bg-emerald-50 px-4 py-3 text-sm text-emerald-800">
                            <svg class="mt-0.5 size-4 shrink-0" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round"
                                stroke-linejoin="round" aria-hidden="true">
                                <path d="m5 12 5 5L20 7" />
                            </svg>
                            {{ session('concern_status') }}
                        </p>
                    @endif

                    <fieldset @if ($concernErrors->has('topic')) aria-describedby="concern-topic-error" @endif>
                        <legend class="mb-2 text-xs font-semibold text-text-primary">What is it about?</legend>
                        <div class="flex flex-wrap gap-2">
                            @foreach (ConcernTopic::cases() as $topic)
                                <label class="relative">
                                    <input type="radio" name="topic" value="{{ $topic->value }}" required class="peer sr-only" @checked($chosenTopic === $topic->value)>
                                    <span class="inline-flex min-h-10 cursor-pointer items-center rounded-full border border-border bg-bg-light px-3.5 text-sm font-medium transition peer-checked:border-primary peer-checked:bg-primary peer-checked:text-white peer-focus-visible:ring-4 peer-focus-visible:ring-secondary/40 hover:border-primary">
                                        {{ $topic->label() }}
                                    </span>
                                </label>
                            @endforeach
                        </div>
                        @if ($concernErrors->has('topic'))
                            <p id="concern-topic-error" class="{{ $fieldError }}">{{ $concernErrors->first('topic') }}</p>
                        @endif
                    </fieldset>

                    <div>
                        <label for="concern-message" class="mb-1.5 block text-xs font-semibold text-text-primary">Message</label>
                        <textarea id="concern-message" name="message" rows="5" required minlength="10" maxlength="{{ StoreConcernRequest::MESSAGE_MAX }}"
                            placeholder="For example: I can't sign in on my phone, or ComLab 3 is in the wrong place on the map."
                            class="login-input resize-y"
                            @if ($concernErrors->has('message')) aria-invalid="true" aria-describedby="concern-message-error" @endif>{{ old('message') }}</textarea>
                        @if ($concernErrors->has('message'))
                            <p id="concern-message-error" class="{{ $fieldError }}">{{ $concernErrors->first('message') }}</p>
                        @endif
                    </div>

                    <div>
                        <button type="submit"
                            class="inline-flex min-h-11 items-center gap-2 rounded-lg bg-primary px-5 text-sm font-semibold text-white transition hover:bg-primary-hover disabled:opacity-60">
                            <svg class="size-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"
                                aria-hidden="true">
                                <path d="M21 3 10 14M21 3l-7 18-4-7-7-4z" />
                            </svg>
                            Send message
                        </button>
                    </div>
                </form>
            </section>

            <aside aria-label="Contact details" class="flex flex-col gap-6 lg:col-start-2 lg:row-span-2 lg:row-start-1">
                <section aria-labelledby="contact-heading" class="rounded-2xl border border-border bg-surface p-5 sm:p-6">
                    <h2 id="contact-heading" class="font-semibold">Contact the administrator</h2>
                    <p class="mt-0.5 mb-4 text-sm text-text-muted">For something urgent, or if you can't sign in.</p>
                    @include('partials.contact-details')
                </section>

                {{-- $emergencyContacts: the visible ones with a number (set on /admin/settings). None: no block. --}}
                @if ($emergencyContacts->isNotEmpty())
                    <section aria-labelledby="emergency-heading" class="rounded-2xl border border-alarm/25 bg-surface p-5 sm:p-6">
                        <h2 id="emergency-heading" class="font-semibold text-alarm">Emergency numbers</h2>
                        <dl class="mt-2 divide-y divide-border">
                            @foreach ($emergencyContacts as $contact)
                                <div class="flex items-center justify-between gap-3 py-2.5 text-sm">
                                    <dt class="min-w-0">
                                        <span class="block font-semibold">{{ $contact->name }}</span>
                                        @if ($contact->note)
                                            <span class="block text-xs text-text-muted">{{ $contact->note }}</span>
                                        @endif
                                    </dt>
                                    <dd class="shrink-0">
                                        <a href="{{ $contact->telHref() }}" aria-label="Call {{ $contact->name }}, {{ $contact->phone }}"
                                            class="inline-flex min-h-11 items-center font-semibold text-primary tabular-nums underline decoration-secondary/60 underline-offset-4 transition hover:decoration-primary">
                                            {{ $contact->phone }}
                                        </a>
                                    </dd>
                                </div>
                            @endforeach
                        </dl>
                    </section>
                @endif
            </aside>

            <section aria-labelledby="messages-heading" class="rounded-2xl border border-border bg-surface lg:col-start-1">
                <div class="px-5 pt-5 sm:px-6">
                    <h2 id="messages-heading" class="font-semibold">Your messages</h2>
                    <p class="mt-0.5 text-sm text-text-muted">The last {{ \App\Http\Controllers\Portal\HelpController::RECENT_CONCERNS }} you sent. The administrator’s replies show here and are emailed to you.</p>
                </div>
                @if ($concerns->isEmpty())
                    <p class="mt-4 border-t border-border px-5 py-4 text-sm text-text-muted sm:px-6">Nothing sent yet. Messages you send show up here with their status.</p>
                @else
                    <ul class="mt-4 divide-y divide-border border-t border-border">
                        @foreach ($concerns as $concern)
                            {{-- id: the bell's "The administrator replied" opens the page here. --}}
                            <li id="concern-{{ $concern->id }}" class="grid scroll-mt-24 gap-1 px-5 py-4 target:bg-secondary/10 sm:px-6">
                                <div class="flex flex-wrap items-center justify-between gap-2">
                                    <p class="text-sm font-semibold">{{ $concern->topic->label() }}</p>
                                    <span @class([
                                        'inline-flex items-center gap-1.5 rounded-full px-2.5 py-0.5 text-xs font-semibold',
                                        'bg-emerald-50 text-emerald-800' => $concern->isResolved(),
                                        'bg-secondary/20 text-primary' => ! $concern->isResolved(),
                                    ])>
                                        <span @class(['size-1.5 rounded-full', 'bg-emerald-600' => $concern->isResolved(), 'bg-secondary-hover' => ! $concern->isResolved()]) aria-hidden="true"></span>
                                        {{ $concern->isResolved() ? 'Resolved' : 'Open' }}
                                    </span>
                                </div>
                                <p class="line-clamp-2 text-sm break-words text-text-muted">{{ $concern->message }}</p>
                                <p class="text-xs text-text-muted">
                                    Sent <time datetime="{{ $concern->created_at->toIso8601String() }}">{{ $campusDate($concern->created_at) }}</time>
                                </p>
                                @foreach ($concern->replies as $reply)
                                    <div class="mt-2 rounded-xl border-l-4 border-secondary bg-bg-light px-4 py-3">
                                        <p class="text-xs font-semibold text-primary">
                                            The administrator replied
                                            <span class="font-normal text-text-muted">· <time datetime="{{ $reply->created_at->toIso8601String() }}">{{ $campusDate($reply->created_at) }}</time></span>
                                        </p>
                                        <p class="mt-1 text-sm leading-6 whitespace-pre-line break-words">{{ $reply->message }}</p>
                                    </div>
                                @endforeach
                            </li>
                        @endforeach
                    </ul>
                @endif
            </section>
        </div>
    </div>
@endsection
