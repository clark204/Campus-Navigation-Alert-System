{{-- A person's initials, tinted by role, with a green ring and dot while they're online (Admin > Accounts).
     Needs $account (User); $size: 'md' (lists) or 'lg' (the account page banner). --}}
@use('App\Enums\UserRole')
@php
    $large = ($size ?? 'md') === 'lg';
    $tint = match ($account->role) {
        UserRole::Student => 'bg-primary/10 text-primary',
        UserRole::Faculty => 'bg-secondary/25 text-primary-hover',
        UserRole::Staff => 'bg-[#a8877a]/20 text-[#5b4038]',
        UserRole::Admin => 'bg-text-primary text-white',
    };
    $online = $account->isOnline();
@endphp
<span class="relative inline-flex shrink-0" @if ($online) title="Online now" @endif>
    <span @class([
        'flex items-center justify-center rounded-full font-semibold',
        'size-10 text-sm' => ! $large,
        'size-16 text-xl' => $large,
        $tint,
        'ring-2 ring-emerald-500 ring-offset-2 ring-offset-surface' => $online,
    ]) aria-hidden="true">{{ $account->initials }}</span>
    @if ($online)
        <span @class(['absolute right-0 bottom-0 rounded-full border-2 border-surface bg-emerald-500', 'size-3' => ! $large, 'size-4' => $large])></span>
        <span class="sr-only">(online now)</span>
    @endif
</span>
