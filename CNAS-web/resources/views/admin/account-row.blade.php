{{-- One person in the Accounts list: the whole row opens their account page. Needs $account (User with
     program.college) and $joined (formats the join date). --}}
<li>
    <a href="{{ route('admin.accounts.show', $account) }}"
        class="group grid grid-cols-[auto_minmax(0,1fr)_auto] items-center gap-x-4 gap-y-1 px-5 py-3.5 transition hover:bg-bg-light hover:shadow-[inset_3px_0_0_var(--color-secondary)] focus-visible:bg-bg-light focus-visible:shadow-[inset_3px_0_0_var(--color-secondary)] focus-visible:outline-none md:grid-cols-[auto_minmax(0,1.4fr)_minmax(0,1fr)_7rem_auto]">
        @include('admin.account-avatar', ['account' => $account])

        <span class="min-w-0">
            <span class="flex items-center gap-2">
                <span class="truncate text-sm font-semibold group-hover:text-primary">{{ $account->full_name }}</span>
                @if ($account->isTrustedForAlerts())
                    <span class="inline-flex shrink-0 items-center gap-1 rounded-full bg-alarm/10 px-2 py-0.5 text-xs font-semibold text-alarm" title="Can send emergency alerts">
                        <svg class="size-3.5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"
                            aria-hidden="true">
                            <path d="M12 3 4 6v6c0 4.5 3.4 8.3 8 9 4.6-.7 8-4.5 8-9V6zm-3 9 2 2 4-4" />
                        </svg>
                        <span class="max-sm:sr-only">Alerts</span>
                    </span>
                @endif
            </span>
            <span class="block truncate text-xs text-text-muted">{{ $account->email }}</span>
        </span>

        <span class="hidden min-w-0 text-xs text-text-muted md:block">
            <span class="block truncate font-medium text-text-primary">{{ $account->role->label() }}@if ($account->school_id) <span class="font-normal text-text-muted tabular-nums">· {{ $account->school_id }}</span>@endif</span>
            <span class="block truncate">{{ $account->program?->college?->name ?? '—' }}</span>
        </span>

        <span class="hidden text-right text-xs text-text-muted tabular-nums md:block">{{ $joined($account->created_at) }}</span>

        <svg class="size-5 text-text-muted transition group-hover:translate-x-0.5 group-hover:text-primary motion-reduce:transition-none" viewBox="0 0 24 24" fill="none"
            stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
            <path d="m9 6 6 6-6 6" />
        </svg>
    </a>
</li>
