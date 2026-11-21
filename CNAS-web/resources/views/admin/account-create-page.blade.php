{{-- Admin "Create account" (Admin\AccountController@create). Faculty and staff can't register themselves (registration
     needs a student COR), so the admin makes their accounts here; a student only when they can't register.
     resources/js/account-create.js: fields follow the role (data-for-roles), the preview card follows the typing,
     and the password gets Generate, Show and Copy. Without JavaScript every field shows and the form still works. --}}
@extends('layout.admin-layout')
@use('App\Enums\UserRole')

@section('title', 'Create account')
@section('heading', 'Accounts')

@php
    $label = 'mb-1.5 block text-xs font-semibold text-text-primary';
    $hint = 'mt-1 text-xs text-text-muted';
    $fieldError = 'mt-1 text-xs text-red-600';
    $chosenRole = old('role', UserRole::Faculty->value);
    $roleCards = [
        UserRole::Faculty->value => ['Teaches classes. Can be trusted to send emergency alerts.', 'M4 6.5A2.5 2.5 0 0 1 6.5 4H20v14H6.5A2.5 2.5 0 0 0 4 20.5zm0 0v14M8 8h8'],
        UserRole::Staff->value => ['Works in an office, the clinic or security. Can be trusted to send alerts.', 'M4 21V7l8-4 8 4v14M9 21v-6h6v6M9 10h.01M15 10h.01'],
        UserRole::Student->value => ['Usually registers with their COR. Make one here only if they can’t.', 'M12 4 2 9l10 5 10-5zM6 11v5c3 2 9 2 12 0v-5'],
    ];
@endphp

@section('content.admin')
    <div class="mx-auto flex max-w-6xl flex-col gap-6">
        <div>
            <a href="{{ route('admin.accounts.index') }}" class="text-sm font-semibold text-primary underline-offset-4 hover:underline">Accounts</a>
            <h1 class="mt-1 text-2xl font-semibold tracking-tight text-primary sm:text-3xl">Create account</h1>
            <p class="mt-1 text-sm text-text-muted">They can sign in as soon as you save, on the website and the mobile app.</p>
        </div>

        <form method="POST" action="{{ route('admin.accounts.store') }}" novalidate data-submit-once data-account-create
            class="grid items-start gap-6 lg:grid-cols-[minmax(0,1.55fr)_minmax(18rem,1fr)]">
            @csrf

            <div class="flex flex-col gap-6">
                {{-- 1. Role --}}
                <fieldset class="rounded-2xl border border-border bg-surface p-5" @error('role') aria-describedby="role-error" @enderror>
                    <legend class="sr-only">What kind of account?</legend>
                    <p class="font-semibold" aria-hidden="true">What kind of account?</p>
                    <div class="mt-3 grid gap-3 sm:grid-cols-3">
                        @foreach ($roles as $option)
                            <label class="relative">
                                <input type="radio" name="role" value="{{ $option->value }}" @checked($chosenRole === $option->value) class="peer sr-only" data-role-choice>
                                <span class="flex h-full cursor-pointer flex-col gap-2 rounded-xl border-2 border-border p-4 transition peer-checked:border-primary peer-checked:bg-primary/[.04] peer-checked:shadow-[inset_0_-3px_0_var(--color-secondary)] peer-focus-visible:ring-4 peer-focus-visible:ring-secondary/40 hover:border-primary/50">
                                    <svg class="size-6 text-primary" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round"
                                        stroke-linejoin="round" aria-hidden="true">
                                        <path d="{{ $roleCards[$option->value][1] }}" />
                                    </svg>
                                    <span class="font-semibold">{{ $option->label() }}</span>
                                    <span class="text-xs leading-5 text-text-muted">{{ $roleCards[$option->value][0] }}</span>
                                </span>
                            </label>
                        @endforeach
                    </div>
                    @error('role') <p id="role-error" class="{{ $fieldError }}">{{ $message }}</p> @enderror
                </fieldset>

                {{-- 2. Who they are --}}
                <section aria-labelledby="who-heading" class="grid gap-4 rounded-2xl border border-border bg-surface p-5 sm:grid-cols-3">
                    <h2 id="who-heading" class="font-semibold sm:col-span-3">Who they are</h2>
                    @foreach (['first_name' => 'First name', 'middle_name' => 'Middle name', 'last_name' => 'Last name'] as $field => $text)
                        <div>
                            <label for="{{ $field }}" class="{{ $label }}">{{ $text }} @if ($field === 'middle_name') <span class="font-normal text-text-muted">(optional)</span> @endif</label>
                            <input id="{{ $field }}" name="{{ $field }}" type="text" maxlength="100" autocomplete="off" value="{{ old($field) }}"
                                @if ($field !== 'middle_name') required @endif data-preview-source="{{ $field }}" class="login-input"
                                @error($field) aria-invalid="true" aria-describedby="{{ $field }}-error" @enderror>
                            @error($field) <p id="{{ $field }}-error" class="{{ $fieldError }}">{{ $message }}</p> @enderror
                        </div>
                    @endforeach

                    <div data-for-roles="student faculty staff">
                        <label for="school_id" class="{{ $label }}">
                            <span data-id-label-student @if ($chosenRole !== 'student') hidden @endif>Student ID</span>
                            <span data-id-label-employee @if ($chosenRole === 'student') hidden @endif>Employee ID <span class="font-normal text-text-muted">(optional)</span></span>
                        </label>
                        <input id="school_id" name="school_id" type="text" maxlength="20" autocomplete="off" value="{{ old('school_id') }}" data-preview-source="school_id"
                            class="login-input tabular-nums" aria-describedby="school_id-hint @error('school_id') school_id-error @enderror" @error('school_id') aria-invalid="true" @enderror>
                        <p id="school_id-hint" class="{{ $hint }}">Students: the 8 digits on their COR.</p>
                        @error('school_id') <p id="school_id-error" class="{{ $fieldError }}">{{ $message }}</p> @enderror
                    </div>

                    <div class="sm:col-span-2" data-for-roles="student faculty">
                        <label for="program_code" class="{{ $label }}">Program <span data-program-optional class="font-normal text-text-muted" @if ($chosenRole === 'student') hidden @endif>(optional for faculty)</span></label>
                        <select id="program_code" name="program_code" data-preview-source="program_code" class="login-input"
                            @error('program_code') aria-invalid="true" aria-describedby="program_code-error" @enderror>
                            <option value="">No program</option>
                            @foreach ($programs as $college => $collegePrograms)
                                <optgroup label="{{ $college }}">
                                    @foreach ($collegePrograms as $program)
                                        <option value="{{ $program->code }}" @selected(old('program_code') === $program->code)>{{ $program->name }}</option>
                                    @endforeach
                                </optgroup>
                            @endforeach
                        </select>
                        @error('program_code') <p id="program_code-error" class="{{ $fieldError }}">{{ $message }}</p> @enderror
                    </div>
                </section>

                {{-- 3. Signing in --}}
                <section aria-labelledby="signin-heading" class="grid gap-4 rounded-2xl border border-border bg-surface p-5">
                    <h2 id="signin-heading" class="font-semibold">Signing in</h2>
                    <div>
                        <label for="email" class="{{ $label }}">Email</label>
                        <input id="email" name="email" type="email" required maxlength="255" autocomplete="off" autocapitalize="none" spellcheck="false"
                            value="{{ old('email') }}" data-preview-source="email" class="login-input"
                            @error('email') aria-invalid="true" aria-describedby="email-error" @enderror>
                        @error('email') <p id="email-error" class="{{ $fieldError }}">{{ $message }}</p> @enderror
                    </div>
                    <div>
                        <label for="password" class="{{ $label }}">Temporary password</label>
                        <div class="flex flex-wrap gap-2">
                            <input id="password" name="password" type="password" required autocomplete="new-password" data-password-field
                                class="login-input min-w-0 flex-1 font-medium tracking-wide" aria-describedby="password-hint @error('password') password-error @enderror"
                                @error('password') aria-invalid="true" @enderror>
                            <button type="button" data-password-generate hidden
                                class="min-h-11 rounded-xl border border-border px-4 text-sm font-semibold text-primary transition hover:border-secondary hover:bg-secondary/10">Generate</button>
                            <button type="button" data-password-show hidden aria-pressed="false"
                                class="min-h-11 rounded-xl border border-border px-4 text-sm font-semibold text-text-primary transition hover:border-primary">Show</button>
                            <button type="button" data-password-copy hidden
                                class="min-h-11 rounded-xl border border-border px-4 text-sm font-semibold text-text-primary transition hover:border-primary">Copy</button>
                        </div>
                        <p id="password-hint" class="{{ $hint }}">At least 8 characters, with letters and numbers. Give it to them in person; they can change it in Settings.</p>
                        <p data-password-status aria-live="polite" class="mt-1 text-xs font-medium text-emerald-700 empty:hidden"></p>
                        @error('password') <p id="password-error" class="{{ $fieldError }}">{{ $message }}</p> @enderror
                    </div>
                </section>

                {{-- 4. Alerts (faculty and staff) --}}
                <label data-for-roles="faculty staff"
                    class="flex cursor-pointer items-start gap-4 rounded-2xl border border-border bg-surface p-5 transition has-checked:border-alarm/50 has-checked:bg-alarm/[.03]">
                    <input type="checkbox" name="trusted_for_alerts" value="1" @checked(old('trusted_for_alerts')) data-preview-source="trusted_for_alerts"
                        class="mt-1 size-5 shrink-0 accent-[#B3121B]">
                    <span>
                        <span class="block font-semibold">Can send emergency alerts</span>
                        <span class="mt-0.5 block text-sm text-text-muted">For security guards, the clinic and DRRM staff. They get an Emergency alerts page in their portal to send an alert straight away and end it. You can change this any time.</span>
                    </span>
                </label>

                <div class="flex flex-wrap items-center gap-3">
                    <button type="submit"
                        class="inline-flex min-h-12 items-center gap-2 rounded-xl bg-primary px-6 text-sm font-bold text-white shadow-md transition hover:bg-primary-hover disabled:opacity-60">
                        Create account
                    </button>
                    <a href="{{ route('admin.accounts.index') }}" class="text-sm font-semibold text-text-muted underline-offset-4 hover:text-primary hover:underline">Cancel</a>
                </div>
            </div>

            {{-- Live preview of the account being made (account-create.js). --}}
            <aside aria-label="Preview" class="lg:sticky lg:top-24">
                <div class="overflow-hidden rounded-2xl border border-border bg-surface shadow-[0_24px_48px_-28px_rgba(88,24,37,.45)]">
                    <div class="relative h-20 bg-primary">
                        <div class="absolute inset-x-0 bottom-0 h-1 bg-secondary"></div>
                        <svg class="absolute top-3 right-4 size-10 text-white/15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.4"
                            aria-hidden="true">
                            <path d="M12 21s-7-6.2-7-12a7 7 0 0 1 14 0c0 5.8-7 12-7 12zm0-9.5a2.5 2.5 0 1 0 0-5 2.5 2.5 0 0 0 0 5z" />
                        </svg>
                    </div>
                    {{-- relative: painted above the banner, so the avatar overlaps it instead of hiding under it. --}}
                    <div class="relative -mt-9 px-5 pb-5">
                        <span data-preview="initials"
                            class="flex size-[4.5rem] items-center justify-center rounded-full border-4 border-surface bg-secondary text-2xl font-semibold text-primary-hover">?</span>
                        <p data-preview="name" class="mt-3 text-lg leading-tight font-semibold break-words">New account</p>
                        <p class="mt-1 flex flex-wrap items-center gap-2 text-sm">
                            <span data-preview="role" class="rounded-full bg-primary/10 px-2.5 py-0.5 text-xs font-semibold text-primary">{{ UserRole::from($chosenRole)->label() }}</span>
                            <span data-preview="trusted" hidden class="inline-flex items-center gap-1 rounded-full bg-alarm/10 px-2.5 py-0.5 text-xs font-semibold text-alarm">Can send alerts</span>
                        </p>
                        <dl class="mt-4 grid gap-2 border-t border-border pt-4 text-sm">
                            <div class="flex justify-between gap-3"><dt class="text-text-muted">Email</dt><dd data-preview="email" class="min-w-0 truncate font-medium">—</dd></div>
                            <div class="flex justify-between gap-3"><dt class="text-text-muted">ID</dt><dd data-preview="school_id" class="font-medium tabular-nums">—</dd></div>
                            <div class="flex justify-between gap-3"><dt class="text-text-muted">Program</dt><dd data-preview="program_code" class="min-w-0 truncate text-right font-medium">—</dd></div>
                        </dl>
                    </div>
                </div>
                <p class="mt-3 px-1 text-xs text-text-muted">This is how they’ll show up in Accounts.</p>
            </aside>
        </form>
    </div>
@endsection
