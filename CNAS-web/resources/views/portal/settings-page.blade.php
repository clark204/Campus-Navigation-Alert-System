{{-- Portal "Settings" (Portal\SettingsController) for students and faculty: the admin settings design with three
     tabs. The server opens $activeTab (?tab=, Profile by default) and hides the other panels;
     resources/js/settings-tabs.js switches tabs without a reload. Account forms save on their own, with their own
     message (admin/settings-message) and error bags (shared AccountController). --}}
@extends('layout.portal-layout')
@use('App\Enums\AlertSeverity')
@use('Illuminate\Support\Str')

@section('title', 'Settings')
@section('heading', 'Settings')

@php
    $value = fn (string $bag, string $field, ?string $saved) => $errors->getBag($bag)->any() ? old($field, $saved) : $saved;

    $label = 'mb-1.5 block text-xs font-semibold text-text-primary';
    $hint = 'mt-1 text-xs text-text-muted';
    $fieldError = 'mt-1 text-xs text-red-600';
    $primaryButton = 'rounded-lg bg-primary px-4 py-2.5 text-sm font-semibold text-white transition hover:bg-primary-hover disabled:opacity-60';
    $portal = $role->value;

    $fullName = implode(' ', array_filter([$user->first_name, $user->middle_name, $user->last_name]));
    $details = array_filter([
        'Full name' => $fullName,
        'School ID' => $user->school_id,
        'Program' => $user->program?->name,
        'College' => $user->program?->college?->name,
        'Email' => $user->email,
        'Account created' => $user->created_at?->timezone('Asia/Manila')->format('F j, Y'),
    ]);
@endphp

@section('content.portal')
    <div class="mx-auto flex max-w-3xl flex-col gap-6">
        <div>
            <h1 class="text-2xl font-semibold tracking-tight text-primary sm:text-3xl">Settings</h1>
            <p class="mt-1 text-sm text-text-muted">Your profile, how you sign in, and emergency alerts and directions on this device.</p>
        </div>

        {{-- Plain links without JavaScript (?tab= opens the tab); with it, tabs switch in place (settings-tabs.js). --}}
        <div role="tablist" aria-label="Settings" data-settings-tabs class="grid grid-cols-2 gap-2 sm:flex sm:flex-wrap">
            @foreach ($tabs as $id => $name)
                <a id="tab-{{ $id }}" role="tab" href="{{ route("{$portal}.settings", ['tab' => $id]) }}" aria-controls="{{ $id }}"
                    aria-selected="{{ $id === $activeTab ? 'true' : 'false' }}"
                    class="inline-flex min-h-11 items-center justify-center gap-2 rounded-lg border border-border bg-surface px-2 text-center sm:px-4 text-sm font-semibold text-text-primary transition focus-visible:ring-4 focus-visible:ring-secondary/40 focus-visible:outline-none aria-selected:border-primary aria-selected:bg-primary aria-selected:text-white aria-[selected=false]:hover:border-primary aria-[selected=false]:hover:text-primary">
                    <span data-tab-label>{{ $name }}</span>
                    <span data-tab-dirty hidden class="size-2 shrink-0 rounded-full bg-secondary"><span class="sr-only">(unsaved changes)</span></span>
                </a>
            @endforeach
        </div>

        {{-- 1. Profile (read-only: it comes from the COR) --}}
        <section id="profile" role="tabpanel" aria-labelledby="tab-profile" @if ($activeTab !== 'profile') hidden @endif
            class="overflow-hidden rounded-2xl border border-border bg-surface">
            <div class="flex items-center gap-4 bg-primary px-5 py-5 text-white shadow-[inset_0_-3px_0_var(--color-secondary)]">
                <span class="flex size-14 shrink-0 items-center justify-center rounded-full bg-secondary text-lg font-semibold text-primary-hover" aria-hidden="true">{{ $user->initials }}</span>
                <div class="min-w-0">
                    <h2 class="truncate text-lg font-semibold">{{ $user->full_name }}</h2>
                    <p class="truncate text-sm text-white/75 tabular-nums">{{ implode(' · ', array_filter([$user->role->label(), $user->school_id])) }}</p>
                </div>
            </div>

            <dl class="divide-y divide-border">
                @foreach ($details as $term => $detail)
                    <div class="grid gap-0.5 px-5 py-3.5 sm:grid-cols-[10rem_minmax(0,1fr)] sm:gap-4">
                        <dt class="text-xs font-medium text-text-muted sm:pt-0.5">{{ $term }}</dt>
                        <dd class="text-sm font-semibold break-words @if ($term === 'School ID') tabular-nums @endif">{{ $detail }}</dd>
                    </div>
                @endforeach
            </dl>

            <div class="flex flex-wrap items-center justify-between gap-x-4 gap-y-3 border-t border-border bg-bg-light px-5 py-4">
                <p class="max-w-md text-sm text-text-muted">Your name, school ID and program come from your COR, so you can't change them here. Something wrong?</p>
                <a href="{{ route("{$portal}.help", ['topic' => 'account']) }}"
                    class="inline-flex min-h-11 items-center rounded-lg border border-border bg-surface px-4 text-sm font-semibold text-primary transition hover:border-secondary hover:bg-secondary/10">
                    Tell the administrator
                </a>
            </div>
        </section>

        {{-- 2. Account: email and password --}}
        <section id="my-account" role="tabpanel" aria-labelledby="tab-my-account" @if ($activeTab !== 'my-account') hidden @endif
            class="overflow-hidden rounded-2xl border border-border bg-surface">
            <div class="px-5 pt-5">
                <h2 id="my-account-heading" class="font-semibold">Email and password</h2>
                <p class="mt-1 text-sm text-text-muted">What you use to sign in to the website and the mobile app.</p>
                @include('admin.settings-message', ['section' => 'account', 'class' => 'mt-4'])
            </div>

            @include('partials.account-email-form', ['routes' => "{$portal}.settings.account"])

            <form method="POST" action="{{ route("{$portal}.settings.account.password") }}" novalidate data-submit-once class="grid gap-4 border-t border-border px-5 py-5 sm:grid-cols-2">
                @csrf
                @method('PUT')
                <h3 class="text-sm font-semibold sm:col-span-2">Password</h3>
                <div class="sm:col-span-2">
                    <label for="current_password" class="{{ $label }}">Current password</label>
                    <input id="current_password" name="current_password" type="password" required autocomplete="current-password" class="login-input"
                        @error('current_password', 'accountPassword') aria-invalid="true" aria-describedby="current_password-error" @enderror>
                    @error('current_password', 'accountPassword') <p id="current_password-error" class="{{ $fieldError }}">{{ $message }}</p> @enderror
                </div>
                <div>
                    <label for="password" class="{{ $label }}">New password</label>
                    <input id="password" name="password" type="password" required autocomplete="new-password" class="login-input"
                        aria-describedby="password-hint @error('password', 'accountPassword') password-error @enderror"
                        @error('password', 'accountPassword') aria-invalid="true" @enderror>
                    <p id="password-hint" class="{{ $hint }}">At least 8 characters, with letters and numbers.</p>
                    @error('password', 'accountPassword') <p id="password-error" class="{{ $fieldError }}">{{ $message }}</p> @enderror
                </div>
                <div>
                    <label for="password_confirmation" class="{{ $label }}">New password again</label>
                    <input id="password_confirmation" name="password_confirmation" type="password" required autocomplete="new-password" class="login-input">
                </div>
                <p class="text-xs text-text-muted sm:col-span-2">Changing it signs you out on every other browser and in the mobile app. This browser stays signed in.</p>
                <div class="sm:col-span-2">
                    <button type="submit" class="{{ $primaryButton }}">Change password</button>
                </div>
            </form>
        </section>

        {{-- 3. Alerts: push on this device, and what each level does --}}
        <section id="alerts" role="tabpanel" aria-labelledby="tab-alerts" @if ($activeTab !== 'alerts') hidden @endif>
            <div class="flex flex-col gap-6">
                @include('partials.push-alerts', ['variant' => 'card'])

                <noscript>
                    <p class="rounded-2xl border border-border bg-surface p-5 text-sm text-text-muted">Turn on JavaScript to turn emergency alerts on or off for this device.</p>
                </noscript>

                <div class="overflow-hidden rounded-2xl border border-border bg-surface">
                    <div class="px-5 pt-5">
                        <h2 class="font-semibold">What each alert means</h2>
                        <p class="mt-1 text-sm text-text-muted">The administrator picks a level for every alert. While CNAS is open you get all of these, even with notifications off.</p>
                    </div>
                    <ul class="mt-4 divide-y divide-border border-t border-border">
                        @foreach (AlertSeverity::cases() as $level)
                            <li class="grid grid-cols-[auto_minmax(0,1fr)] gap-x-3 px-5 py-4">
                                <span class="mt-1 size-3 rounded-full ring-4 ring-bg-light" style="background: {{ $level->color() }}" aria-hidden="true"></span>
                                <div>
                                    <h3 class="text-sm font-semibold">{{ $level->label() }} <span class="font-normal text-text-muted">· {{ $level->description() }}</span></h3>
                                    <p class="mt-0.5 text-sm text-text-muted">You get {{ Str::lcfirst(Str::after($level->studentsGet(), 'Students get: ')) }}</p>
                                </div>
                            </li>
                        @endforeach
                    </ul>
                </div>
            </div>
        </section>

        {{-- 4. Navigation: spoken directions on this browser (localStorage, partials/voice-directions) --}}
        <section id="navigation" role="tabpanel" aria-labelledby="tab-navigation" @if ($activeTab !== 'navigation') hidden @endif>
            <div class="flex flex-col gap-6">
                @include('partials.voice-directions', ['variant' => 'card'])
                <noscript>
                    <p class="rounded-2xl border border-border bg-surface p-5 text-sm text-text-muted">Turn on JavaScript to use voice directions on this device.</p>
                </noscript>
            </div>
        </section>
    </div>
@endsection
