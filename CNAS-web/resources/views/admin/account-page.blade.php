{{-- One account in Admin > Accounts (Admin\AccountController@show): who they are (with "Edit details", not for admin
     accounts), activity, the "Can send emergency alerts" switch (faculty and staff), and the Danger zone. Deleting works like deleting a GitHub repository: the
     admin opens the Danger zone, reads what will be removed and types the account's email; the button stays disabled
     until it matches (resources/js/account-delete.js) and the server checks it again (DeleteAccountRequest). Admin
     accounts, including your own, can't be deleted here. --}}
@extends('layout.admin-layout')
@use('App\Enums\UserRole')

@php
    $breadcrumbs = [['label' => $account->full_name]];
@endphp

@section('title', $account->full_name)
@section('heading', 'Accounts')

@php
    $campusTime = fn ($time) => $time?->timezone('Asia/Manila')->format('M j, Y, g:i A');
    $lastSignIn = $account->latestLoginActivity;
    $presence = match (true) {
        $account->isOnline() => 'Online now',
        $account->last_seen_at !== null => 'Last seen '.$account->last_seen_at->diffForHumans(),
        default => 'Hasn’t signed in yet',
    };
    $details = array_filter([
        'Full name' => implode(' ', array_filter([$account->first_name, $account->middle_name, $account->last_name])),
        $account->role === UserRole::Student ? 'Student ID' : 'Employee ID' => $account->school_id,
        'Program' => $account->program?->name,
        'College' => $account->program?->college?->name,
        'Email' => $account->email.($account->needsEmailVerification() ? ' (not verified yet)' : ''),
        'Last sign-in' => $lastSignIn ? $campusTime($lastSignIn->logged_in_at).' · '.$lastSignIn->device_type->label().' · '.$lastSignIn->channel->label() : null,
    ]);
    $deleteErrors = $errors->getBag('deleteAccount');
    $trusted = $account->isTrustedForAlerts();
@endphp

@section('content.admin')
    <div class="flex flex-col gap-6">
        {{-- Banner --}}
        <section aria-labelledby="account-name" class="overflow-hidden rounded-2xl border border-border bg-surface">
            <div class="relative bg-primary px-5 pt-5 pb-12 text-white sm:px-6">
                <div class="absolute inset-x-0 bottom-0 h-1 bg-secondary"></div>
                <p class="flex items-center gap-2 text-sm text-white/80">
                    <span @class(['size-2 rounded-full', 'bg-emerald-400' => $account->isOnline(), 'bg-white/40' => ! $account->isOnline()]) aria-hidden="true"></span>
                    {{ $presence }}
                </p>
            </div>
            {{-- relative: painted above the banner (also positioned), so only the avatar overlaps it. --}}
            <div class="relative flex flex-wrap items-start justify-between gap-x-4 gap-y-3 px-5 pb-5 sm:px-6">
                <div class="flex min-w-0 items-start gap-4">
                    <span class="-mt-10 shrink-0 rounded-full border-4 border-surface bg-surface">@include('admin.account-avatar', ['account' => $account, 'size' => 'lg'])</span>
                    <div class="min-w-0 pt-3">
                        <h1 id="account-name" class="truncate text-xl font-semibold tracking-tight sm:text-2xl">{{ $account->full_name }}</h1>
                        <p class="truncate text-sm text-text-muted">{{ $account->email }}</p>
                    </div>
                </div>
                <div class="flex flex-wrap gap-2 pt-4">
                    <span class="rounded-full bg-primary/10 px-3 py-1 text-xs font-semibold text-primary">{{ $account->role->label() }}</span>
                    @if ($trusted)
                        <span class="inline-flex items-center gap-1 rounded-full bg-alarm/10 px-3 py-1 text-xs font-semibold text-alarm">
                            <svg class="size-3.5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"
                                aria-hidden="true">
                                <path d="M12 3 4 6v6c0 4.5 3.4 8.3 8 9 4.6-.7 8-4.5 8-9V6zm-3 9 2 2 4-4" />
                            </svg>
                            Can send alerts
                        </span>
                    @endif
                </div>
            </div>

            <dl class="grid grid-cols-3 divide-x divide-border border-t border-border">
                @foreach ([
                    ['Sign-ins', number_format($signInCount), 'last '.\App\Models\LoginActivity::RETENTION_DAYS.' days'],
                    ['Alerts sent', number_format($alertsSent), $account->canSendAlerts() ? 'can send' : 'can’t send'],
                    ['Joined', $account->created_at?->timezone('Asia/Manila')->format('M j, Y') ?? 'Unknown', $account->created_at?->diffForHumans() ?? 'no date recorded'],
                ] as [$term, $value, $note])
                    <div class="px-4 py-4 sm:px-6">
                        <dt class="text-xs text-text-muted">{{ $term }}</dt>
                        <dd class="mt-0.5 text-lg font-semibold tabular-nums sm:text-xl">{{ $value }}</dd>
                        <dd class="text-xs text-text-muted">{{ $note }}</dd>
                    </div>
                @endforeach
            </dl>
        </section>

        <div class="grid items-start gap-6 lg:grid-cols-[minmax(0,1.3fr)_minmax(0,1fr)]">
            <section aria-labelledby="details-heading" class="rounded-2xl border border-border bg-surface">
                <div class="flex items-center justify-between gap-3 px-5 pt-4 pb-2">
                    <h2 id="details-heading" class="font-semibold">Details</h2>
                    @unless ($account->isAdmin())
                        <a href="{{ route('admin.accounts.edit', $account) }}"
                            class="inline-flex min-h-9 items-center gap-1.5 rounded-lg border border-border px-3 text-sm font-semibold text-primary transition hover:border-secondary hover:bg-secondary/10">
                            <svg class="size-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                                <path d="M4 20h4L19 9a2.8 2.8 0 0 0-4-4L4 16zM13.5 6.5l4 4" />
                            </svg>
                            Edit details
                        </a>
                    @endunless
                </div>
                <dl class="divide-y divide-border">
                    @foreach ($details as $term => $detail)
                        <div class="grid gap-0.5 px-5 py-3 sm:grid-cols-[8rem_minmax(0,1fr)] sm:gap-4">
                            <dt class="text-xs font-medium text-text-muted sm:pt-0.5">{{ $term }}</dt>
                            <dd class="text-sm font-semibold break-words">{{ $detail }}</dd>
                        </div>
                    @endforeach
                </dl>
            </section>

            <div class="flex flex-col gap-6">
            {{-- Emergency alerts: the switch is a form, so it works without JavaScript. --}}
            <section aria-labelledby="alerts-heading" @class([
                'rounded-2xl border bg-surface p-5 transition',
                'border-alarm/40 shadow-[inset_4px_0_0_var(--color-alarm)]' => $trusted,
                'border-border' => ! $trusted,
            ])>
                <div class="flex items-start justify-between gap-4">
                    <div>
                        <h2 id="alerts-heading" class="font-semibold">Can send emergency alerts</h2>
                        <p class="mt-1 text-sm text-text-muted">
                            @if ($account->isAdmin())
                                Administrators can always send alerts.
                            @elseif (! $account->canBeTrustedForAlerts())
                                Only faculty and staff can be trusted to send alerts.
                            @elseif ($trusted)
                                On since {{ $account->alert_trusted_at->timezone('Asia/Manila')->format('M j, Y') }}. They can send an alert from their portal and end it. You can see that they sent it.
                            @else
                                Off. Turn it on for people who must warn everyone fast, like security guards or the clinic.
                            @endif
                        </p>
                    </div>
                    @if ($account->canBeTrustedForAlerts())
                        <form method="POST" action="{{ route('admin.accounts.trust', $account) }}" class="shrink-0"
                            @unless ($trusted) data-confirm="Let {{ $account->full_name }} send emergency alerts to everyone?" @endunless>
                            @csrf
                            @method('PATCH')
                            <input type="hidden" name="trusted" value="{{ $trusted ? 0 : 1 }}">
                            <button type="submit" role="switch" aria-checked="{{ $trusted ? 'true' : 'false' }}" aria-labelledby="alerts-heading"
                                class="group relative inline-flex h-8 w-14 items-center rounded-full transition focus-visible:ring-4 focus-visible:ring-secondary/40 focus-visible:outline-none aria-checked:bg-alarm aria-[checked=false]:bg-border">
                                <span class="absolute left-1 size-6 rounded-full bg-white shadow transition-transform group-aria-checked:translate-x-6 motion-reduce:transition-none"></span>
                            </button>
                        </form>
                    @endif
                </div>
            </section>

            {{-- Faculty or staff: faculty get "My classes", staff (who don't teach) don't. --}}
            @if (in_array($account->role, [UserRole::Faculty, UserRole::Staff], true))
                <section aria-labelledby="role-heading" class="rounded-2xl border border-border bg-surface p-5">
                    <h2 id="role-heading" class="font-semibold">Faculty or staff</h2>
                    <p class="mt-1 text-sm text-text-muted">Faculty can add the classes they teach and see them on Home. Staff don’t teach, so they don’t get My classes. Switch someone who teaches to Faculty.</p>
                    <form method="POST" action="{{ route('admin.accounts.role', $account) }}" class="mt-4 flex flex-wrap items-center gap-3">
                        @csrf
                        @method('PATCH')
                        <fieldset class="flex rounded-xl border border-border bg-bg-light p-1">
                            <legend class="sr-only">Role</legend>
                            @foreach ([UserRole::Faculty, UserRole::Staff] as $option)
                                <label>
                                    <input type="radio" name="role" value="{{ $option->value }}" @checked($account->role === $option) class="peer sr-only">
                                    <span class="block cursor-pointer rounded-lg px-4 py-2 text-sm font-semibold text-text-muted transition peer-checked:bg-primary peer-checked:text-white peer-focus-visible:ring-4 peer-focus-visible:ring-secondary/40">
                                        {{ $option->label() }}
                                    </span>
                                </label>
                            @endforeach
                        </fieldset>
                        <button type="submit" class="inline-flex min-h-10 items-center rounded-lg border border-border px-4 text-sm font-semibold text-primary transition hover:border-secondary hover:bg-secondary/10">
                            Save role
                        </button>
                    </form>
                </section>
            @endif

            {{-- Temporary password: for someone who forgot theirs. They choose their own at the next sign-in. --}}
            @unless ($account->isAdmin())
                @php($passwordErrors = $errors->getBag('temporaryPassword'))
                <section aria-labelledby="temporary-password-heading" class="rounded-2xl border border-border bg-surface p-5">
                    <div class="flex flex-wrap items-center justify-between gap-2">
                        <h2 id="temporary-password-heading" class="font-semibold">Temporary password</h2>
                        @if ($account->must_change_password)
                            <span class="rounded-full bg-secondary/20 px-2.5 py-0.5 text-xs font-semibold text-primary">Waiting for them to change it</span>
                        @endif
                    </div>
                    <p class="mt-1 text-sm text-text-muted">
                        For someone who forgot their password{{ $account->role === UserRole::Student ? ' and can’t use “Forgot password?”' : '' }}.
                        They’re signed out everywhere and must choose their own when they sign in.
                    </p>
                    <details @if ($passwordErrors->any()) open @endif class="group/temp mt-4">
                        <summary class="inline-flex min-h-10 cursor-pointer list-none items-center gap-2 rounded-lg border border-border px-4 text-sm font-semibold text-primary transition select-none hover:border-secondary hover:bg-secondary/10 group-open/temp:hidden [&::-webkit-details-marker]:hidden">
                            Set temporary password
                        </summary>
                        <form method="POST" action="{{ route('admin.accounts.password', $account) }}" novalidate data-submit-once data-password-tools
                            data-confirm="Set a temporary password for {{ $account->full_name }}? They’ll be signed out everywhere." class="grid gap-3">
                            @csrf
                            @method('PATCH')
                            <div>
                                <label for="temporary-password" class="mb-1.5 block text-xs font-semibold text-text-primary">Temporary password</label>
                                <div class="flex flex-wrap gap-2">
                                    <input id="temporary-password" name="password" type="password" required autocomplete="new-password" data-password-field
                                        class="login-input min-w-0 flex-1 font-medium tracking-wide"
                                        aria-describedby="temporary-password-hint @if ($passwordErrors->has('password')) temporary-password-error @endif"
                                        @if ($passwordErrors->has('password')) aria-invalid="true" @endif>
                                    <button type="button" data-password-generate hidden
                                        class="min-h-11 rounded-xl border border-border px-4 text-sm font-semibold text-primary transition hover:border-secondary hover:bg-secondary/10">Generate</button>
                                    <button type="button" data-password-show hidden aria-pressed="false"
                                        class="min-h-11 rounded-xl border border-border px-4 text-sm font-semibold text-text-primary transition hover:border-primary">Show</button>
                                    <button type="button" data-password-copy hidden
                                        class="min-h-11 rounded-xl border border-border px-4 text-sm font-semibold text-text-primary transition hover:border-primary">Copy</button>
                                </div>
                                <p id="temporary-password-hint" class="mt-1 text-xs text-text-muted">At least 8 characters, with letters and numbers. Give it to them in person; it isn’t shown again.</p>
                                <p data-password-status aria-live="polite" class="mt-1 text-xs font-medium text-emerald-700 empty:hidden"></p>
                                @if ($passwordErrors->has('password'))
                                    <p id="temporary-password-error" class="mt-1 text-xs text-red-600">{{ $passwordErrors->first('password') }}</p>
                                @endif
                            </div>
                            <div>
                                <button type="submit" class="inline-flex min-h-10 items-center rounded-lg bg-primary px-4 text-sm font-semibold text-white transition hover:bg-primary-hover disabled:opacity-60">
                                    Set temporary password
                                </button>
                            </div>
                        </form>
                    </details>
                </section>
            @endunless
            </div>
        </div>

        {{-- Danger zone --}}
        <section id="danger-zone" aria-labelledby="danger-heading" class="scroll-mt-24">
            <h2 id="danger-heading" class="mb-2 font-semibold text-red-700">Danger zone</h2>
            <div class="rounded-2xl border-2 border-red-300 bg-surface">
                @if ($canDelete)
                    <details @if ($deleteErrors->any()) open @endif class="group/delete">
                        <summary class="flex cursor-pointer list-none flex-wrap items-center justify-between gap-3 p-5 [&::-webkit-details-marker]:hidden">
                            <span class="min-w-0">
                                <span class="block text-sm font-semibold">Delete this account</span>
                                <span class="block text-sm text-text-muted">Once it’s deleted, it’s gone. There is no undo.</span>
                            </span>
                            <span class="inline-flex min-h-10 items-center rounded-lg border border-red-300 px-4 text-sm font-semibold text-red-700 transition group-open/delete:hidden hover:bg-red-600 hover:text-white">
                                Delete this account
                            </span>
                            <span class="hidden text-sm font-semibold text-text-muted group-open/delete:inline">Cancel</span>
                        </summary>

                        <form method="POST" action="{{ route('admin.accounts.destroy', $account) }}" novalidate data-submit-once data-account-delete
                            data-expected="{{ $account->email }}" class="grid gap-4 border-t border-red-200 bg-red-50/40 p-5">
                            @csrf
                            @method('DELETE')
                            <div class="text-sm">
                                <p class="font-semibold">This permanently deletes {{ $account->full_name }}’s account and signs them out everywhere.</p>
                                @if ($deletes)
                                    <ul class="mt-2 grid gap-1 text-text-muted">
                                        @foreach ($deletes as $what => $count)
                                            <li class="flex items-center gap-2">
                                                <span class="size-1.5 rounded-full bg-red-400" aria-hidden="true"></span>
                                                <span><strong class="text-text-primary tabular-nums">{{ number_format($count) }}</strong> {{ $what }}</span>
                                            </li>
                                        @endforeach
                                    </ul>
                                @endif
                                <p class="mt-2 text-text-muted">Alerts they sent and concerns they wrote are kept, without their name. Their phone keeps getting emergency alerts.</p>
                            </div>
                            <div>
                                <label for="confirm_email" class="mb-1.5 block text-sm">
                                    To confirm, type <strong class="rounded bg-red-100 px-1.5 py-0.5 font-semibold break-all text-red-800 select-all">{{ $account->email }}</strong> below
                                </label>
                                <input id="confirm_email" name="confirm_email" type="text" required autocomplete="off" autocapitalize="none" spellcheck="false"
                                    data-delete-confirm class="login-input"
                                    @if ($deleteErrors->has('confirm_email')) aria-invalid="true" aria-describedby="confirm_email-error" @endif>
                                @if ($deleteErrors->has('confirm_email'))
                                    <p id="confirm_email-error" class="mt-1 text-xs text-red-600">{{ $deleteErrors->first('confirm_email') }}</p>
                                @endif
                            </div>
                            <button type="submit" data-delete-submit
                                class="min-h-11 rounded-lg bg-red-600 px-4 text-sm font-bold text-white transition hover:bg-red-700 disabled:cursor-not-allowed disabled:opacity-40">
                                I understand, delete this account
                            </button>
                        </form>
                    </details>
                @else
                    <p class="p-5 text-sm text-text-muted">
                        {{ $account->isAdmin() ? 'Administrator accounts can’t be deleted here, so the console always has an admin.' : 'You can’t delete your own account.' }}
                    </p>
                @endif
            </div>
        </section>
    </div>
@endsection
