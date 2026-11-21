{{-- A round avatar with initials for the Concerns inbox: the sender's ($user, or their $email's first letter for a
     visitor), or the administrator's ($admin = true, maroon). $size: 'sm' (list rows) or 'md' (the conversation). --}}
@php
    $size ??= 'sm';
    $admin ??= false;
    $initials = $user?->initials ?? mb_strtoupper(mb_substr((string) ($email ?? '?'), 0, 1));
@endphp
<span @class([
    'grid shrink-0 place-items-center rounded-full font-semibold',
    'size-8 text-xs' => $size === 'sm',
    'size-10 text-sm' => $size === 'md',
    'bg-primary text-white' => $admin,
    'bg-secondary/25 text-primary-hover' => ! $admin,
]) aria-hidden="true">{{ $initials }}</span>
