{{-- Admin "Concerns" inbox (Admin\ConcernController@index), laid out like an email inbox: folders (Open, Resolved:
     ?status=resolved) on the left, one row per concern on the right, newest first. A row shows the sender, the
     number of messages in the conversation, the topic, the start of the message and when it came in, and opens the
     conversation (admin/concern-page). $unreadIds: concerns this admin hasn't opened yet (bold, with a dot). Times
     are campus time: the time for today, the date for this year, the full date before. --}}
@extends('layout.admin-layout')

@php
    $now = now()->timezone('Asia/Manila');
    $listTime = function ($time) use ($now) {
        $local = $time->timezone('Asia/Manila');

        return match (true) {
            $local->isSameDay($now) => $local->format('g:i A'),
            $local->isSameYear($now) => $local->format('M j'),
            default => $local->format('n/j/y'),
        };
    };
    $folders = [
        'open' => ['Open', $openCount, 'M4 13h4l2 3h4l2-3h4M5 5h14l1 8v6H4v-6z'],
        'resolved' => ['Resolved', $resolvedCount, 'M5 12.5l4.5 4.5L19 7.5'],
    ];
    $current = $showResolved ? 'resolved' : 'open';
@endphp

@section('title', 'Concerns')
@section('heading', 'Concerns')

@section('content.admin')
    <div class="flex flex-col gap-5">
        <div>
            <h1 class="text-2xl font-semibold tracking-tight text-primary sm:text-3xl">Concerns</h1>
            <p class="mt-1 text-sm text-text-muted">Messages from Help &amp; contact and the site footer. Your reply is emailed to them and shows in their portal.</p>
        </div>

        <div class="grid items-start gap-4 lg:grid-cols-[13rem_minmax(0,1fr)]">
            {{-- Folders --}}
            <nav aria-label="Folders" class="flex gap-2 lg:flex-col lg:gap-1">
                @foreach ($folders as $folder => [$name, $count, $icon])
                    <a href="{{ route('admin.concerns.index', $folder === 'open' ? [] : ['status' => $folder]) }}"
                        @if ($folder === $current) aria-current="page" @endif
                        class="group flex min-h-11 items-center gap-3 rounded-full px-4 text-sm font-semibold text-text-primary transition hover:bg-black/5 aria-[current=page]:bg-secondary/25 aria-[current=page]:text-primary-hover max-lg:border max-lg:border-border max-lg:bg-surface">
                        <svg class="size-5 shrink-0 text-text-muted group-aria-[current=page]:text-primary" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                            <path d="{{ $icon }}" />
                        </svg>
                        <span class="flex-1">{{ $name }}</span>
                        <span class="text-xs tabular-nums text-text-muted group-aria-[current=page]:text-primary-hover">{{ $count }}</span>
                    </a>
                @endforeach
            </nav>

            {{-- The list --}}
            <section aria-label="{{ $folders[$current][0] }} concerns" class="overflow-hidden rounded-2xl border border-border bg-surface">
                <div class="flex items-center justify-between gap-3 border-b border-border px-4 py-2.5 text-xs text-text-muted">
                    <span>{{ $showResolved ? 'Resolved, most recently resolved first' : 'Open, newest first' }}</span>
                    @if ($concerns->total() > 0)
                        <span class="flex items-center gap-1 tabular-nums">
                            {{ $concerns->firstItem() }}–{{ $concerns->lastItem() }} of {{ $concerns->total() }}
                            @foreach ([['Newer', $concerns->previousPageUrl(), 'm15 6-6 6 6 6'], ['Older', $concerns->nextPageUrl(), 'm9 6 6 6-6 6']] as [$label, $url, $path])
                                @if ($url)
                                    <a href="{{ $url }}" aria-label="{{ $label }}" class="grid size-8 place-items-center rounded-full text-text-primary hover:bg-black/5">
                                        <svg class="size-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="{{ $path }}" /></svg>
                                    </a>
                                @else
                                    <span class="grid size-8 place-items-center rounded-full opacity-35" aria-hidden="true">
                                        <svg class="size-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="{{ $path }}" /></svg>
                                    </span>
                                @endif
                            @endforeach
                        </span>
                    @endif
                </div>

                @if ($concerns->isEmpty())
                    <div class="px-5 py-14 text-center">
                        <p class="font-semibold">{{ $showResolved ? 'Nothing resolved yet' : 'No open concerns' }}</p>
                        <p class="mt-1 text-sm text-text-muted">
                            {{ $showResolved ? 'Concerns you mark resolved are kept here.' : 'New messages from students, faculty and visitors show up here.' }}
                        </p>
                    </div>
                @else
                    <ul class="divide-y divide-border">
                        @foreach ($concerns as $concern)
                            @php
                                $sender = $concern->user;
                                $unread = in_array($concern->id, $unreadIds, true);
                                $messages = 1 + $concern->replies_count;
                                $when = $showResolved ? $concern->resolved_at : $concern->created_at;
                            @endphp
                            <li>
                                <a href="{{ route('admin.concerns.show', $concern) }}"
                                    @class([
                                        'group relative grid grid-cols-[auto_minmax(0,1fr)_auto] items-center gap-x-3 gap-y-0.5 px-4 py-3 transition hover:z-10 hover:shadow-[0_1px_3px_rgba(61,15,24,.18)] sm:grid-cols-[auto_12rem_minmax(0,1fr)_auto]',
                                        'bg-surface' => $unread,
                                        'bg-bg-light/60' => ! $unread,
                                    ])>
                                    @if ($unread)
                                        <span class="absolute inset-y-0 left-0 w-1 bg-primary" aria-hidden="true"></span>
                                    @endif
                                    @include('admin.concern-avatar', ['user' => $sender, 'email' => $concern->email])

                                    <p @class(['truncate text-sm', 'font-bold text-text-primary' => $unread, 'text-text-primary' => ! $unread])>
                                        @if ($unread)<span class="sr-only">Unread: </span>@endif{{ $sender?->full_name ?? 'Visitor' }}
                                        @if ($messages > 1)<span class="ml-0.5 text-xs font-normal text-text-muted tabular-nums" aria-label="{{ $messages }} messages">{{ $messages }}</span>@endif
                                    </p>

                                    <p class="col-span-2 col-start-2 row-start-2 flex min-w-0 items-center gap-2 text-sm sm:col-span-1 sm:col-start-3 sm:row-start-1">
                                        <span class="shrink-0 rounded-md bg-secondary/20 px-1.5 py-0.5 text-[0.6875rem] font-semibold text-primary">{{ $concern->topic->shortLabel() }}</span>
                                        <span class="truncate">
                                            <span @class(['font-bold text-text-primary' => $unread, 'text-text-primary' => ! $unread])>{{ $concern->topic->label() }}</span>
                                            <span class="text-text-muted"> – {{ \Illuminate\Support\Str::limit(preg_replace('/\s+/', ' ', $concern->message), 140) }}</span>
                                        </span>
                                    </p>

                                    <span class="col-start-3 row-start-1 flex items-center gap-1.5 justify-self-end sm:col-start-4">
                                        @if ($concern->replies_count > 0)
                                            <svg class="size-3.5 text-text-muted" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-label="Replied"><path d="M9 14 4 9l5-5" /><path d="M4 9h10.5a5.5 5.5 0 0 1 0 11H11" /></svg>
                                        @endif
                                        <time datetime="{{ $when->toIso8601String() }}" @class(['text-xs tabular-nums whitespace-nowrap', 'font-bold text-text-primary' => $unread, 'text-text-muted' => ! $unread])>{{ $listTime($when) }}</time>
                                    </span>
                                </a>
                            </li>
                        @endforeach
                    </ul>
                @endif
            </section>
        </div>
    </div>
@endsection
