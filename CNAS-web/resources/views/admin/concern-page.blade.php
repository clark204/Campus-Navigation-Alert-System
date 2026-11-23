{{-- One conversation in the Concerns inbox (Admin\ConcernController@show), like an opened email thread: the topic as
     the subject, the sender's message, the administrator's replies in order, and the reply box at the bottom ("Mark as
     resolved" ticked by default; errors in the bag reply{id}). The reply is emailed to the concern's email and, when the
     sender has an account, shows in their portal (bell and Help & contact). Opening it marks it read. $backUrl: the
     folder it is in. Times are campus time. --}}
@extends('layout.admin-layout')

@php
    $campusTime = fn ($time) => $time->timezone('Asia/Manila')->format('M j, Y, g:i A');
    $sender = $concern->user;
    $replyErrors = $errors->getBag('reply'.$concern->id);
    $iconButton = 'inline-flex min-h-10 items-center gap-2 rounded-full px-3.5 text-sm font-semibold transition';
@endphp

@section('title', $concern->topic->label().' · Concerns')
@section('heading', 'Concerns')

@section('content.admin')
    <div class="mx-auto flex max-w-4xl flex-col gap-4">
        {{-- Toolbar --}}
        <div class="flex flex-wrap items-center justify-between gap-2">
            <a href="{{ $backUrl }}" class="{{ $iconButton }} -ml-3 text-text-primary hover:bg-black/5">
                <svg class="size-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M19 12H5m6-6-6 6 6 6" /></svg>
                Back to {{ $concern->isResolved() ? 'Resolved' : 'Open' }}
            </a>
            <div class="flex flex-wrap gap-2">
                <a href="#reply" class="{{ $iconButton }} border border-border bg-surface text-primary hover:border-secondary hover:bg-secondary/10">
                    <svg class="size-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M9 14 4 9l5-5" /><path d="M4 9h10.5a5.5 5.5 0 0 1 0 11H11" /></svg>
                    Reply
                </a>
                <form method="POST" action="{{ route($concern->isResolved() ? 'admin.concerns.reopen' : 'admin.concerns.resolve', $concern) }}" data-submit-once>
                    @csrf
                    @method('PATCH')
                    <button type="submit" @class([
                        $iconButton,
                        'bg-primary text-white hover:bg-primary-hover' => ! $concern->isResolved(),
                        'border border-border bg-surface text-text-primary hover:border-primary hover:text-primary' => $concern->isResolved(),
                    ])>
                        <svg class="size-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                            <path d="{{ $concern->isResolved() ? 'M4 12a8 8 0 1 0 2.3-5.6M4 4v4h4' : 'M5 12.5l4.5 4.5L19 7.5' }}" />
                        </svg>
                        {{ $concern->isResolved() ? 'Reopen' : 'Mark resolved' }}
                    </button>
                </form>
            </div>
        </div>

        <article aria-labelledby="concern-subject" class="overflow-hidden rounded-2xl border border-border bg-surface">
            {{-- Subject --}}
            <header class="flex flex-wrap items-center gap-x-3 gap-y-2 border-b border-border px-5 py-4 sm:px-6">
                <h1 id="concern-subject" class="text-xl font-semibold tracking-tight text-text-primary sm:text-2xl">{{ $concern->topic->label() }}</h1>
                <span class="rounded-md bg-secondary/20 px-2 py-0.5 text-xs font-semibold text-primary">{{ $concern->topic->shortLabel() }}</span>
                <span @class([
                    'inline-flex items-center gap-1.5 rounded-full px-2.5 py-0.5 text-xs font-semibold',
                    'bg-emerald-50 text-emerald-800' => $concern->isResolved(),
                    'bg-primary/10 text-primary' => ! $concern->isResolved(),
                ])>
                    {{ $concern->isResolved() ? 'Resolved '.$concern->resolved_at->timezone('Asia/Manila')->format('M j') : 'Open' }}
                </span>
            </header>

            <ol class="divide-y divide-border">
                {{-- Their message --}}
                <li class="flex gap-3 px-5 py-5 sm:gap-4 sm:px-6">
                    @include('admin.concern-avatar', ['user' => $sender, 'email' => $concern->email, 'size' => 'md'])
                    <div class="min-w-0 flex-1">
                        <div class="flex flex-wrap items-baseline justify-between gap-x-3 gap-y-0.5">
                            <p class="min-w-0 text-sm">
                                <span class="font-semibold text-text-primary">{{ $sender?->full_name ?? 'Visitor' }}</span>
                                <span class="break-all text-text-muted">&lt;{{ $concern->email }}&gt;</span>
                            </p>
                            <time datetime="{{ $concern->created_at->toIso8601String() }}" class="text-xs text-text-muted tabular-nums">{{ $campusTime($concern->created_at) }}</time>
                        </div>
                        @if ($sender)
                            <p class="text-xs text-text-muted">
                                {{ implode(' · ', array_filter([$sender->role->label(), $sender->school_id])) }}
                                · <a href="{{ route('admin.accounts.show', $sender) }}" class="font-semibold text-primary underline-offset-4 hover:underline">Open account</a>
                            </p>
                        @endif
                        <p class="mt-3 text-[0.9375rem] leading-7 whitespace-pre-line break-words text-text-primary">{{ $concern->message }}</p>
                    </div>
                </li>

                {{-- Replies --}}
                @foreach ($concern->replies as $reply)
                    @php($byMe = $reply->author?->is(auth()->user()))
                    <li class="flex gap-3 bg-bg-light/50 px-5 py-5 sm:gap-4 sm:px-6">
                        @include('admin.concern-avatar', ['user' => $reply->author, 'email' => 'A', 'size' => 'md', 'admin' => true])
                        <div class="min-w-0 flex-1">
                            <div class="flex flex-wrap items-baseline justify-between gap-x-3 gap-y-0.5">
                                <p class="min-w-0 text-sm">
                                    <span class="font-semibold text-text-primary">{{ $byMe ? 'You' : ($reply->author?->full_name ?? 'An administrator') }}</span>
                                    <span class="text-text-muted">to {{ $concern->email }}</span>
                                </p>
                                <time datetime="{{ $reply->created_at->toIso8601String() }}" class="text-xs text-text-muted tabular-nums">{{ $campusTime($reply->created_at) }}</time>
                            </div>
                            <p class="mt-3 text-[0.9375rem] leading-7 whitespace-pre-line break-words text-text-primary">{{ $reply->message }}</p>
                        </div>
                    </li>
                @endforeach
            </ol>
        </article>

        {{-- Reply box --}}
        <form id="reply" method="POST" action="{{ route('admin.concerns.reply', $concern) }}" novalidate data-submit-once
            class="scroll-mt-24 overflow-hidden rounded-2xl border border-border bg-surface shadow-[0_8px_24px_-16px_rgba(61,15,24,.35)] focus-within:border-secondary">
            @csrf
            <div class="flex items-center gap-2 border-b border-border px-5 py-3 text-sm sm:px-6">
                <svg class="size-4 text-text-muted" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M9 14 4 9l5-5" /><path d="M4 9h10.5a5.5 5.5 0 0 1 0 11H11" /></svg>
                <span class="text-text-muted">To</span>
                <span class="min-w-0 truncate font-semibold text-text-primary">{{ $sender?->full_name ? $sender->full_name.' <'.$concern->email.'>' : $concern->email }}</span>
            </div>
            <label for="reply-message" class="sr-only">Your reply to {{ $sender?->first_name ?? $concern->email }}</label>
            <textarea id="reply-message" name="message" rows="6" maxlength="{{ \App\Services\ConcernInbox::REPLY_MAX }}" required
                placeholder="Write your reply…"
                class="block w-full resize-y border-0 bg-transparent px-5 py-4 text-[0.9375rem] leading-7 placeholder:text-text-muted/70 focus:ring-0 focus:outline-none sm:px-6"
                @if ($replyErrors->has('message')) aria-invalid="true" aria-describedby="reply-error" @endif>{{ $replyErrors->any() ? old('message') : '' }}</textarea>
            @if ($replyErrors->has('message'))
                <p id="reply-error" class="px-5 pb-2 text-xs text-red-600 sm:px-6">{{ $replyErrors->first('message') }}</p>
            @endif
            <div class="flex flex-wrap items-center gap-3 border-t border-border bg-bg-light/50 px-5 py-3 sm:px-6">
                <button type="submit" data-loading-text="Sending…"
                    class="inline-flex min-h-10 items-center gap-2 rounded-full bg-primary px-5 text-sm font-semibold text-white transition hover:bg-primary-hover disabled:cursor-wait">
                    <span data-submit-spinner hidden class="size-4 animate-spin rounded-full border-2 border-white/35 border-t-white motion-reduce:animate-none" aria-hidden="true"></span>
                    <span data-submit-label>Send</span>
                </button>
                @unless ($concern->isResolved())
                    <label class="flex items-center gap-2 text-sm text-text-primary">
                        <input type="hidden" name="resolve" value="0">
                        <input type="checkbox" name="resolve" value="1" @checked($replyErrors->any() ? old('resolve') : true) class="size-4 rounded accent-primary">
                        Mark as resolved
                    </label>
                @endunless
                <p class="ml-auto text-xs text-text-muted">Emailed to them{{ $sender ? ', and shown in their portal' : '' }}.</p>
            </div>
        </form>
    </div>
@endsection
