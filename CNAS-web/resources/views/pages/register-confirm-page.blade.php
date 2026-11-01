@extends('layout.register-layout', ['step' => 2])

@section('title', 'TCC NAV · Create account')

@section('content.register')
    @php($nameFields = ['first_name', 'middle_name', 'last_name'])
    {{-- Reopen "Edit name" after a failed submit that touched the name. --}}
    @php($editingName = $errors->hasAny($nameFields) || collect($nameFields)->contains(fn ($field) => old($field) !== null))

    <form method="POST" action="{{ route('register.store') }}" class="mt-6 grid gap-4" data-submit-once novalidate>
        @csrf
        {{-- One key per form view; a repeated submit of it is answered without creating a second account. --}}
        <input type="hidden" name="idempotency_key" value="{{ $idempotencyKey }}">

        <section aria-labelledby="cor-verified-heading" class="rounded-xl border border-emerald-200 bg-emerald-50/60 px-4 py-3">
            <h3 id="cor-verified-heading" class="flex items-center gap-2 text-xs font-bold text-emerald-800">
                <svg class="size-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round"
                    stroke-linejoin="round" aria-hidden="true">
                    <path d="M20 6 9 17l-5-5" />
                </svg>
                COR verified
            </h3>

            <dl class="mt-2 grid gap-x-4 gap-y-2 text-sm sm:grid-cols-2">
                <div class="sm:col-span-2">
                    <dt class="text-xs text-text-muted">Name</dt>
                    <dd class="font-semibold text-text-primary">{{ $displayName }}</dd>
                </div>
                <div>
                    <dt class="text-xs text-text-muted">Student ID</dt>
                    <dd class="font-semibold text-text-primary">{{ $cor['student_id'] }}</dd>
                </div>
                <div>
                    <dt class="text-xs text-text-muted">College</dt>
                    <dd class="font-semibold text-text-primary">{{ $collegeName }}</dd>
                </div>
            </dl>

            {{-- Closed: the fields are disabled (by app.js) and not sent, so the parser's split is used. --}}
            <details data-name-editor @if ($editingName) open @endif class="mt-3 border-t border-emerald-200/80 pt-2">
                <summary class="w-fit cursor-pointer text-xs font-semibold text-primary underline-offset-4 hover:underline">Edit name</summary>

                <fieldset class="mt-3 grid gap-3 sm:grid-cols-3">
                    <legend class="sr-only">Your name</legend>
                    @foreach ([
                        ['id' => 'first_name', 'label' => 'First name'],
                        ['id' => 'middle_name', 'label' => 'Middle name'],
                        ['id' => 'last_name', 'label' => 'Last name'],
                    ] as $field)
                        <div>
                            <label for="{{ $field['id'] }}" class="mb-1.5 block text-xs font-semibold text-text-primary">{{ $field['label'] }}</label>
                            {{-- autocomplete off: browser autofill must not change a name that has to match the COR. --}}
                            <input id="{{ $field['id'] }}" name="{{ $field['id'] }}" type="text" autocomplete="off"
                                value="{{ old($field['id'], $cor[$field['id']] ?? '') }}" class="login-input"
                                @error($field['id']) aria-invalid="true" aria-describedby="{{ $field['id'] }}-error" @enderror>
                        </div>
                    @endforeach

                    @foreach ($nameFields as $field)
                        @error($field) <p id="{{ $field }}-error" class="text-xs text-red-600 sm:col-span-3">{{ $message }}</p> @enderror
                    @endforeach
                </fieldset>
            </details>
        </section>

        <div>
            <label for="email" class="mb-1.5 block text-xs font-semibold text-text-primary">Email</label>
            <input id="email" name="email" type="email" autocomplete="email" autocapitalize="none"
                spellcheck="false" required value="{{ old('email') }}" placeholder="example@gmail.com" class="login-input"
                @error('email') aria-invalid="true" aria-describedby="email-error" @enderror>
            @error('email') <p id="email-error" class="mt-1 text-xs text-red-600">{{ $message }}</p> @enderror
        </div>

        <div class="grid gap-4 sm:grid-cols-2">
            @foreach ([
                ['id' => 'password', 'label' => 'Password', 'placeholder' => 'Create a password'],
                ['id' => 'password_confirmation', 'label' => 'Confirm password', 'placeholder' => 'Re-enter your password'],
            ] as $field)
                <div>
                    <label for="{{ $field['id'] }}" class="mb-1.5 block text-xs font-semibold text-text-primary">{{ $field['label'] }}</label>
                    <div class="relative">
                        <input id="{{ $field['id'] }}" name="{{ $field['id'] }}" type="password" autocomplete="new-password"
                            required placeholder="{{ $field['placeholder'] }}" class="login-input pr-12"
                            @error($field['id']) aria-invalid="true" aria-describedby="{{ $field['id'] }}-error" @enderror>
                        <button type="button" data-password-toggle aria-controls="{{ $field['id'] }}" aria-label="Show password"
                            class="absolute inset-y-0 right-0 flex w-12 items-center justify-center rounded-r-xl text-text-muted transition hover:text-primary">
                            <svg data-icon-show class="size-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" aria-hidden="true">
                                <path d="M2 12s3.6-7 10-7 10 7 10 7-3.6 7-10 7S2 12 2 12Z" />
                                <circle cx="12" cy="12" r="3" />
                            </svg>
                            <svg data-icon-hide class="hidden size-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" aria-hidden="true">
                                <path d="M3 3l18 18M10.6 5.1A10.4 10.4 0 0 1 12 5c6.4 0 10 7 10 7a17.6 17.6 0 0 1-3.2 4.1M6.6 6.6C3.8 8.4 2 12 2 12s3.6 7 10 7a9.7 9.7 0 0 0 5.4-1.6M9.9 9.9a3 3 0 0 0 4.2 4.2" />
                            </svg>
                        </button>
                    </div>
                    @error($field['id']) <p id="{{ $field['id'] }}-error" class="mt-1 text-xs text-red-600">{{ $message }}</p> @enderror
                </div>
            @endforeach
        </div>

        <p class="-mt-1 text-xs text-text-muted">At least 8 characters, with letters and numbers.</p>

        <button type="submit"
            class="mt-2 flex w-full items-center justify-center gap-4 rounded-xl bg-primary px-6 py-3.5 text-sm font-bold text-white shadow-lg transition hover:bg-primary-hover disabled:cursor-wait disabled:opacity-70">
            Create account
        </button>

        <a href="{{ route('register') }}" class="w-fit justify-self-center text-xs font-semibold text-primary underline-offset-4 hover:underline">
            Upload a different COR
        </a>
    </form>
@endsection
