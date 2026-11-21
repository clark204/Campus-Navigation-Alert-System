{{-- Admin "Edit details" for one account (Admin\AccountController@edit, @update, UpdateAccountRequest): name, ID,
     program and email, with the same rules as Create account for the account's role. The role is switched on the
     account page (faculty or staff); passwords are changed only by their owner. A changed email becomes unverified,
     so the person confirms it with a code at their next sign-in. Admin accounts are edited in Settings. --}}
@extends('layout.admin-layout')
@use('App\Enums\UserRole')

@section('title', 'Edit '.$account->full_name)
@section('heading', 'Accounts')

@php
    $label = 'mb-1.5 block text-xs font-semibold text-text-primary';
    $hint = 'mt-1 text-xs text-text-muted';
    $fieldError = 'mt-1 text-xs text-red-600';
    $isStudent = $account->role === UserRole::Student;
    $hasProgram = in_array($account->role, [UserRole::Student, UserRole::Faculty], true);
    $chosenProgram = old('program_code', $account->program_code);
@endphp

@section('content.admin')
    <div class="mx-auto flex max-w-3xl flex-col gap-6">
        <div>
            <a href="{{ route('admin.accounts.show', $account) }}" class="inline-flex items-center gap-1 text-sm font-semibold text-primary underline-offset-4 hover:underline">
                <svg class="size-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                    <path d="m15 6-6 6 6 6" />
                </svg>
                {{ $account->full_name }}
            </a>
            <h1 class="mt-1 text-2xl font-semibold tracking-tight text-primary sm:text-3xl">Edit details</h1>
            <p class="mt-1 text-sm text-text-muted">{{ $account->role->label() }} account. Changes show everywhere at once, including the mobile app.</p>
        </div>

        <form method="POST" action="{{ route('admin.accounts.update', $account) }}" novalidate data-submit-once class="flex flex-col gap-6">
            @csrf
            @method('PUT')

            <section aria-labelledby="who-heading" class="grid gap-4 rounded-2xl border border-border bg-surface p-5 sm:grid-cols-3">
                <h2 id="who-heading" class="font-semibold sm:col-span-3">Who they are</h2>
                @foreach (['first_name' => 'First name', 'middle_name' => 'Middle name', 'last_name' => 'Last name'] as $field => $text)
                    <div>
                        <label for="{{ $field }}" class="{{ $label }}">{{ $text }} @if ($field === 'middle_name') <span class="font-normal text-text-muted">(optional)</span> @endif</label>
                        <input id="{{ $field }}" name="{{ $field }}" type="text" maxlength="100" autocomplete="off" value="{{ old($field, $account->{$field}) }}"
                            @if ($field !== 'middle_name') required @endif class="login-input"
                            @error($field) aria-invalid="true" aria-describedby="{{ $field }}-error" @enderror>
                        @error($field) <p id="{{ $field }}-error" class="{{ $fieldError }}">{{ $message }}</p> @enderror
                    </div>
                @endforeach

                <div>
                    <label for="school_id" class="{{ $label }}">
                        @if ($isStudent) Student ID @else Employee ID <span class="font-normal text-text-muted">(optional)</span> @endif
                    </label>
                    <input id="school_id" name="school_id" type="text" maxlength="20" autocomplete="off" value="{{ old('school_id', $account->school_id) }}"
                        @if ($isStudent) required inputmode="numeric" @endif class="login-input tabular-nums"
                        aria-describedby="school_id-hint @error('school_id') school_id-error @enderror" @error('school_id') aria-invalid="true" @enderror>
                    <p id="school_id-hint" class="{{ $hint }}">{{ $isStudent ? 'The 8 digits on their COR. They can sign in with it.' : 'They can sign in with it.' }}</p>
                    @error('school_id') <p id="school_id-error" class="{{ $fieldError }}">{{ $message }}</p> @enderror
                </div>

                @if ($hasProgram)
                    <div class="sm:col-span-2">
                        <label for="program_code" class="{{ $label }}">Program @unless ($isStudent) <span class="font-normal text-text-muted">(optional)</span> @endunless</label>
                        <select id="program_code" name="program_code" class="login-input" @if ($isStudent) required @endif
                            @error('program_code') aria-invalid="true" aria-describedby="program_code-error" @enderror>
                            @unless ($isStudent) <option value="">No program</option> @endunless
                            @foreach ($programs as $college => $collegePrograms)
                                <optgroup label="{{ $college }}">
                                    @foreach ($collegePrograms as $program)
                                        <option value="{{ $program->code }}" @selected($chosenProgram === $program->code)>{{ $program->name }}</option>
                                    @endforeach
                                </optgroup>
                            @endforeach
                        </select>
                        <p class="{{ $hint }}">Their college follows the program.</p>
                        @error('program_code') <p id="program_code-error" class="{{ $fieldError }}">{{ $message }}</p> @enderror
                    </div>
                @endif
            </section>

            <section aria-labelledby="signin-heading" class="grid gap-4 rounded-2xl border border-border bg-surface p-5">
                <h2 id="signin-heading" class="font-semibold">Signing in</h2>
                <div>
                    <label for="email" class="{{ $label }}">Email</label>
                    <input id="email" name="email" type="email" required maxlength="255" autocomplete="off" autocapitalize="none" spellcheck="false"
                        value="{{ old('email', $account->email) }}" class="login-input"
                        aria-describedby="email-hint @error('email') email-error @enderror" @error('email') aria-invalid="true" @enderror>
                    <p id="email-hint" class="{{ $hint }}">If you change it, they confirm the new email with a code the next time they sign in.</p>
                    @error('email') <p id="email-error" class="{{ $fieldError }}">{{ $message }}</p> @enderror
                </div>
                <p class="rounded-xl bg-bg-light px-4 py-3 text-sm text-text-muted">
                    Passwords can’t be changed here. They change their own in Settings → Account.
                </p>
            </section>

            <div class="flex flex-wrap items-center gap-3">
                <button type="submit" data-loading-text="Saving…"
                    class="inline-flex min-h-12 items-center gap-2 rounded-xl bg-primary px-6 text-sm font-bold text-white shadow-md transition hover:bg-primary-hover disabled:cursor-wait">
                    <span data-submit-spinner hidden class="size-4 animate-spin rounded-full border-2 border-white/35 border-t-white motion-reduce:animate-none" aria-hidden="true"></span>
                    <span data-submit-label>Save changes</span>
                </button>
                <a href="{{ route('admin.accounts.show', $account) }}" class="text-sm font-semibold text-text-muted underline-offset-4 hover:text-primary hover:underline">Cancel</a>
            </div>
        </form>
    </div>
@endsection
