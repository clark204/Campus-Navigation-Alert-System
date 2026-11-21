{{-- The notification bell in the top bar (partials/app-topbar), for the admin and the portals. A <details>, like the
     account menu, so it opens without JavaScript; app.js closes it on Escape, a click outside, or a link.
     $unreadCount and $notifications (the newest few) come from a view composer (AppServiceProvider). Each
     notification's data has title, body and url; opening one (NotificationController@open) marks it read.
     Admins get "New message from …" (App\Notifications\ConcernReceived); students and faculty get "The administrator
     replied …" (ConcernReplied). $class: extra classes (e.g. ml-auto). --}}
@php
    $badge = $unreadCount > 9 ? '9+' : (string) $unreadCount;
    $isAdmin = $user->isAdmin();
    $allUrl = $isAdmin ? route('admin.concerns.index') : route($user->role->portal()->value.'.help');
@endphp
<details data-notification-menu class="group/bell relative {{ $class ?? '' }}">
    <summary aria-label="Notifications{{ $unreadCount ? ", {$unreadCount} unread" : '' }}"
        class="relative flex size-10 cursor-pointer list-none items-center justify-center rounded-xl text-text-muted transition select-none hover:bg-bg-light hover:text-primary focus-visible:ring-4 focus-visible:ring-secondary/40 focus-visible:outline-none group-open/bell:bg-bg-light group-open/bell:text-primary [&::-webkit-details-marker]:hidden">
        <svg class="size-6" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
            <path d="M6 9a6 6 0 1 1 12 0c0 6 2.5 7.5 2.5 7.5h-17S6 15 6 9zM10 20a2.2 2.2 0 0 0 4 0" />
        </svg>
        @if ($unreadCount)
            <span class="absolute top-1 right-1 flex h-4.5 min-w-4.5 items-center justify-center rounded-full bg-secondary px-1 text-[0.6875rem] leading-none font-bold text-primary-hover tabular-nums ring-2 ring-surface" aria-hidden="true">{{ $badge }}</span>
        @endif
    </summary>

    <div class="absolute right-0 z-30 mt-2 w-[24rem] overflow-hidden rounded-xl border border-border bg-surface shadow-lg max-sm:fixed max-sm:inset-x-4 max-sm:top-16 max-sm:w-auto">
        <div class="flex items-center justify-between gap-3 border-b border-border px-4 py-3">
            <p class="font-semibold">Notifications</p>
            @if ($unreadCount)
                <form method="POST" action="{{ route('notifications.read-all') }}">
                    @csrf
                    <button type="submit" class="rounded-md px-1 text-xs font-semibold text-primary underline-offset-4 hover:underline">Mark all as read</button>
                </form>
            @endif
        </div>

        @if ($notifications->isEmpty())
            <div class="px-4 py-8 text-center">
                <span class="mx-auto grid size-11 place-items-center rounded-full bg-bg-light text-text-muted" aria-hidden="true">
                    <svg class="size-6" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round">
                        <path d="M6 9a6 6 0 1 1 12 0c0 6 2.5 7.5 2.5 7.5h-17S6 15 6 9zM10 20a2.2 2.2 0 0 0 4 0" />
                    </svg>
                </span>
                <p class="mt-3 text-sm font-semibold">You’re all caught up</p>
                <p class="mt-1 text-sm text-text-muted">
                    {{ $isAdmin ? 'New messages from students, faculty and visitors show up here.' : 'When the administrator replies to a message you sent, it shows up here.' }}
                </p>
            </div>
        @else
            <ul class="max-h-[min(26rem,65svh)] divide-y divide-border overflow-y-auto">
                @foreach ($notifications as $notification)
                    @php($unread = $notification->read_at === null)
                    <li>
                        <a href="{{ route('notifications.open', $notification->id) }}" data-menu-link
                            @class(['flex gap-3 px-4 py-3 transition hover:bg-bg-light', 'bg-secondary/10' => $unread])>
                            <span @class([
                                'mt-0.5 grid size-9 shrink-0 place-items-center rounded-full',
                                'bg-primary text-white' => $unread,
                                'bg-bg-light text-text-muted' => ! $unread,
                            ]) aria-hidden="true">
                                <svg class="size-4.5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.9" stroke-linecap="round" stroke-linejoin="round">
                                    <path d="M21 12a8 8 0 0 1-11.6 7.1L4 20.5l1.4-4.6A8 8 0 1 1 21 12z" />
                                </svg>
                            </span>
                            <span class="min-w-0 flex-1">
                                <span @class(['block text-sm break-words', 'font-semibold text-text-primary' => $unread, 'text-text-primary' => ! $unread])>
                                    @if ($unread)<span class="sr-only">Unread: </span>@endif{{ $notification->data['title'] ?? 'Notification' }}
                                </span>
                                @if (! empty($notification->data['body']))
                                    <span class="mt-0.5 line-clamp-2 block text-sm break-words text-text-muted">{{ $notification->data['body'] }}</span>
                                @endif
                                <time datetime="{{ $notification->created_at->toIso8601String() }}" class="mt-1 block text-xs text-text-muted">{{ $notification->created_at->diffForHumans() }}</time>
                            </span>
                            @if ($unread)
                                <span class="mt-2 size-2 shrink-0 rounded-full bg-secondary" aria-hidden="true"></span>
                            @endif
                        </a>
                    </li>
                @endforeach
            </ul>
        @endif

        <a href="{{ $allUrl }}" data-menu-link class="block border-t border-border px-4 py-2.5 text-center text-sm font-semibold text-primary hover:bg-bg-light">
            {{ $isAdmin ? 'Open Concerns' : 'Open Help & contact' }}
        </a>
    </div>
</details>
