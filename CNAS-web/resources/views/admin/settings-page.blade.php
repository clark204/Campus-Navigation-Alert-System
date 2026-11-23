{{-- Admin "Settings" (SettingsController): five tabs. The server opens $activeTab (?tab=, Contact details by default)
     and hides the other panels; resources/js/settings-tabs.js switches tabs without a reload. Each section saves on its
     own, with its own message (admin/settings-message) and its own error bag, so an error shows only on its form. --}}
@extends('layout.admin-layout')
@use('App\Http\Requests\Admin\SaveEmergencyContactRequest')

@section('title', 'Settings')
@section('heading', 'Settings')

@php
    // A form's previous input only after that form failed validation: several forms share field names (email, phone).
    $value = fn (string $bag, string $field, ?string $saved) => $errors->getBag($bag)->any() ? old($field, $saved) : $saved;

    $label = 'mb-1.5 block text-xs font-semibold text-text-primary';
    $hint = 'mt-1 text-xs text-text-muted';
    $fieldError = 'mt-1 text-xs text-red-600';
    $primaryButton = 'rounded-lg bg-primary px-4 py-2.5 text-sm font-semibold text-white transition hover:bg-primary-hover disabled:opacity-60';
    $smallButton = 'rounded-lg border border-border px-3 py-1.5 text-xs font-semibold text-primary transition hover:border-secondary hover:bg-secondary/10 disabled:opacity-40';
    $user = request()->user();
@endphp

@section('content.admin')
    <div class="mx-auto flex max-w-3xl flex-col gap-6">
        <div>
            <h1 class="text-2xl font-semibold tracking-tight text-primary sm:text-3xl">Settings</h1>
            <p class="mt-1 text-sm text-text-muted">Contact details, emergency numbers, the academic term, your own account, and whether alerts can reach phones.</p>
        </div>

        {{-- Plain links without JavaScript (?tab= opens the tab); with it, tabs switch in place (settings-tabs.js). --}}
        <div role="tablist" aria-label="Settings" data-settings-tabs class="grid grid-cols-2 gap-2 sm:flex sm:flex-wrap">
            @foreach ($tabs as $id => $name)
                <a id="tab-{{ $id }}" role="tab" href="{{ route('admin.settings', ['tab' => $id]) }}" aria-controls="{{ $id }}"
                    aria-selected="{{ $id === $activeTab ? 'true' : 'false' }}"
                    class="inline-flex min-h-11 items-center justify-center gap-2 rounded-lg border border-border bg-surface px-2 text-center sm:px-4 text-sm font-semibold text-text-primary transition focus-visible:ring-4 focus-visible:ring-secondary/40 focus-visible:outline-none aria-selected:border-primary aria-selected:bg-primary aria-selected:text-white aria-[selected=false]:hover:border-primary aria-[selected=false]:hover:text-primary">
                    <span data-tab-label>{{ $name }}</span>
                    <span data-tab-dirty hidden class="size-2 shrink-0 rounded-full bg-secondary"><span class="sr-only">(unsaved changes)</span></span>
                </a>
            @endforeach
        </div>

        {{-- 1. Contact details --}}
        <section id="contact-details" role="tabpanel" aria-labelledby="tab-contact-details" @if ($activeTab !== 'contact-details') hidden @endif
            class="rounded-2xl border border-border bg-surface p-5">
            <h2 id="contact-details-heading" class="font-semibold">Contact details</h2>
            <p class="mt-1 text-sm text-text-muted">Shown in the site footer and on the Help &amp; contact page of the student and faculty portals.</p>

            @include('admin.settings-message', ['section' => 'contact', 'class' => 'mt-4'])

            <form method="POST" action="{{ route('admin.settings.contact.update') }}" novalidate data-submit-once class="mt-4 grid gap-4 sm:grid-cols-2">
                @csrf
                @method('PUT')

                <div>
                    <label for="contact-email" class="{{ $label }}">Email</label>
                    <input id="contact-email" name="email" type="email" required maxlength="255" autocomplete="off" autocapitalize="none" spellcheck="false"
                        value="{{ $value('contact', 'email', $contact['email']) }}" class="login-input"
                        @error('email', 'contact') aria-invalid="true" aria-describedby="contact-email-error" @enderror>
                    @error('email', 'contact') <p id="contact-email-error" class="{{ $fieldError }}">{{ $message }}</p> @enderror
                </div>

                <div>
                    <label for="contact-phone" class="{{ $label }}">Phone</label>
                    <input id="contact-phone" name="phone" type="tel" required maxlength="30" inputmode="tel" autocomplete="off"
                        value="{{ $value('contact', 'phone', $contact['phone']) }}" placeholder="e.g. +63 912-345-6789" class="login-input"
                        @error('phone', 'contact') aria-invalid="true" aria-describedby="contact-phone-error" @enderror>
                    @error('phone', 'contact') <p id="contact-phone-error" class="{{ $fieldError }}">{{ $message }}</p> @enderror
                </div>

                <div class="sm:col-span-2">
                    <label for="contact-facebook" class="{{ $label }}">Facebook page</label>
                    <input id="contact-facebook" name="facebook" type="url" required maxlength="255" inputmode="url" autocomplete="off" autocapitalize="none" spellcheck="false"
                        value="{{ $value('contact', 'facebook', $contact['facebook']) }}" placeholder="https://www.facebook.com/…" class="login-input"
                        @error('facebook', 'contact') aria-invalid="true" aria-describedby="contact-facebook-error" @enderror>
                    @error('facebook', 'contact') <p id="contact-facebook-error" class="{{ $fieldError }}">{{ $message }}</p> @enderror
                </div>

                <div class="sm:col-span-2">
                    <label for="contact-address" class="{{ $label }}">Address</label>
                    <input id="contact-address" name="address" type="text" required maxlength="200" autocomplete="off"
                        value="{{ $value('contact', 'address', $contact['address']) }}" class="login-input"
                        @error('address', 'contact') aria-invalid="true" aria-describedby="contact-address-error" @enderror>
                    @error('address', 'contact') <p id="contact-address-error" class="{{ $fieldError }}">{{ $message }}</p> @enderror
                </div>

                <div class="sm:col-span-2">
                    <button type="submit" class="{{ $primaryButton }}">Save contact details</button>
                </div>
            </form>
        </section>

        {{-- 2. Emergency contacts --}}
        <section id="emergency-contacts" role="tabpanel" aria-labelledby="tab-emergency-contacts" @if ($activeTab !== 'emergency-contacts') hidden @endif
            class="overflow-hidden rounded-2xl border border-border bg-surface">
            <div class="px-5 pt-5">
                <h2 id="emergency-contacts-heading" class="font-semibold">Emergency contacts</h2>
                <p class="mt-1 text-sm text-text-muted">Shown on the portal home with a tap-to-call number, in this order. A contact needs a number before you can show it.</p>

                @include('admin.settings-message', ['section' => 'emergency', 'class' => 'mt-4', 'error' => $errors->emergencyContacts->first()])
            </div>

            @if ($emergencyContacts->isEmpty())
                <p class="mt-4 border-t border-border px-5 py-4 text-sm text-text-muted">No emergency contacts yet. Add the first one below.</p>
            @else
                <ul class="mt-4 divide-y divide-border border-t border-border">
                    @foreach ($emergencyContacts as $emergencyContact)
                        @php
                            $bag = SaveEmergencyContactRequest::errorBagFor($emergencyContact);
                            $fieldId = "emergency-{$emergencyContact->id}";
                            $shown = $emergencyContact->is_visible && filled($emergencyContact->phone);
                        @endphp
                        <li class="grid gap-3 px-5 py-4">
                            <div class="flex flex-wrap items-start justify-between gap-x-4 gap-y-2">
                                <div class="min-w-0">
                                    <p class="text-sm font-semibold break-words">{{ $emergencyContact->name }}</p>
                                    <p class="text-sm text-text-muted">
                                        @if (filled($emergencyContact->phone))
                                            <span class="tabular-nums">{{ $emergencyContact->phone }}</span>@if ($emergencyContact->note) · {{ $emergencyContact->note }}@endif
                                        @else
                                            <span class="italic">No number yet</span>
                                        @endif
                                    </p>
                                </div>
                                <span @class([
                                    'inline-flex shrink-0 items-center gap-1.5 rounded-full px-2.5 py-1 text-xs font-semibold',
                                    'bg-primary/10 text-primary' => $shown,
                                    'bg-bg-light text-text-muted' => ! $shown,
                                ])>
                                    <svg class="size-3.5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"
                                        stroke-linejoin="round" aria-hidden="true">
                                        @if ($shown)
                                            <path d="M2 12s3.5-7 10-7 10 7 10 7-3.5 7-10 7S2 12 2 12zm10 3a3 3 0 1 0 0-6 3 3 0 0 0 0 6z" />
                                        @else
                                            <path d="m3 3 18 18M10.6 10.6a2 2 0 0 0 2.8 2.8M9.9 5.1A10 10 0 0 1 12 5c6.5 0 10 7 10 7a17 17 0 0 1-3.2 4.1M6.1 6.1A17 17 0 0 0 2 12s3.5 7 10 7a9.7 9.7 0 0 0 5.9-2.1" />
                                        @endif
                                    </svg>
                                    {{ $shown ? 'Shown' : 'Hidden' }}
                                </span>
                            </div>

                            <div class="flex flex-wrap gap-2">
                                <form method="POST" action="{{ route('admin.settings.emergency-contacts.move', $emergencyContact) }}">
                                    @csrf
                                    @method('PATCH')
                                    <input type="hidden" name="direction" value="up">
                                    <button type="submit" @disabled($loop->first) class="{{ $smallButton }}">
                                        Move up<span class="sr-only">: {{ $emergencyContact->name }}</span>
                                    </button>
                                </form>
                                <form method="POST" action="{{ route('admin.settings.emergency-contacts.move', $emergencyContact) }}">
                                    @csrf
                                    @method('PATCH')
                                    <input type="hidden" name="direction" value="down">
                                    <button type="submit" @disabled($loop->last) class="{{ $smallButton }}">
                                        Move down<span class="sr-only">: {{ $emergencyContact->name }}</span>
                                    </button>
                                </form>
                                <form method="POST" action="{{ route('admin.settings.emergency-contacts.visibility', $emergencyContact) }}">
                                    @csrf
                                    @method('PATCH')
                                    <input type="hidden" name="is_visible" value="{{ $emergencyContact->is_visible ? 0 : 1 }}">
                                    <button type="submit" class="{{ $smallButton }}">
                                        {{ $emergencyContact->is_visible ? 'Hide' : 'Show' }}<span class="sr-only">: {{ $emergencyContact->name }}</span>
                                    </button>
                                </form>
                                <form method="POST" action="{{ route('admin.settings.emergency-contacts.destroy', $emergencyContact) }}"
                                    data-confirm="Delete {{ $emergencyContact->name }}? Students will no longer see this number.">
                                    @csrf
                                    @method('DELETE')
                                    <button type="submit" class="rounded-lg border border-red-200 px-3 py-1.5 text-xs font-semibold text-red-700 transition hover:bg-red-50">
                                        Delete<span class="sr-only">: {{ $emergencyContact->name }}</span>
                                    </button>
                                </form>
                            </div>

                            <details @if ($errors->getBag($bag)->any()) open @endif>
                                <summary class="w-fit cursor-pointer text-sm font-semibold text-primary underline-offset-4 hover:underline">
                                    Edit name, number or note<span class="sr-only">: {{ $emergencyContact->name }}</span>
                                </summary>

                                <form method="POST" action="{{ route('admin.settings.emergency-contacts.update', $emergencyContact) }}" novalidate data-submit-once
                                    class="mt-3 grid gap-4 rounded-xl border border-border bg-bg-light p-4 sm:grid-cols-2">
                                    @csrf
                                    @method('PUT')
                                    @include('admin.emergency-contact-fields', ['bag' => $bag, 'fieldId' => $fieldId, 'saved' => $emergencyContact])
                                    <div class="sm:col-span-2">
                                        <button type="submit" class="{{ $primaryButton }}">Save contact</button>
                                    </div>
                                </form>
                            </details>
                        </li>
                    @endforeach
                </ul>
            @endif

            <div class="border-t border-border px-5 py-5">
                <h3 class="text-sm font-semibold">Add a contact</h3>
                <form method="POST" action="{{ route('admin.settings.emergency-contacts.store') }}" novalidate data-submit-once class="mt-3 grid gap-4 sm:grid-cols-2">
                    @csrf
                    @include('admin.emergency-contact-fields', ['bag' => SaveEmergencyContactRequest::errorBagFor(null), 'fieldId' => 'emergency-new', 'saved' => null])
                    <div class="sm:col-span-2">
                        <button type="submit" class="{{ $primaryButton }}">Add contact</button>
                    </div>
                </form>
            </div>
        </section>

        {{-- 3. Academic term (AcademicTermController): students can only register with a COR for the current term. --}}
        @php($termErrors = $errors->getBag('academicTerm'))
        <section id="academic-term" role="tabpanel" aria-labelledby="tab-academic-term" @if ($activeTab !== 'academic-term') hidden @endif
            class="overflow-hidden rounded-2xl border border-border bg-surface">
            <div class="px-5 pt-5">
                <h2 class="font-semibold">Academic term</h2>
                <p class="mt-1 text-sm text-text-muted">Students can only register with a COR for the current term. Change it at the start of each semester.</p>
                @include('admin.settings-message', ['section' => 'term', 'class' => 'mt-4'])

                <div class="mt-4 rounded-xl bg-bg-light px-4 py-3">
                    <p class="text-xs font-semibold text-text-muted">Current term</p>
                    @if ($currentTerm)
                        <p class="mt-0.5 text-lg font-semibold text-primary">{{ $currentTerm->label() }}</p>
                        @if ($currentTerm->set_current_at)
                            <p class="text-xs text-text-muted">Set {{ $currentTerm->set_current_at->diffForHumans() }}</p>
                        @endif
                    @else
                        <p class="mt-0.5 text-sm font-semibold text-red-700">None. Registration is closed until you set one.</p>
                    @endif
                </div>
            </div>

            <form method="POST" action="{{ route('admin.academic-terms.store') }}" novalidate data-submit-once class="grid gap-4 p-5 sm:grid-cols-[1fr_1fr_auto] sm:items-end">
                @csrf
                <div>
                    <label for="academic_year" class="{{ $label }}">Academic year</label>
                    <input id="academic_year" name="academic_year" type="text" inputmode="numeric" maxlength="9" required
                        value="{{ $termErrors->any() ? old('academic_year') : '' }}" placeholder="e.g. 2026-2027" class="login-input"
                        @if ($termErrors->has('academic_year')) aria-invalid="true" aria-describedby="academic_year-error" @endif>
                    @if ($termErrors->has('academic_year')) <p id="academic_year-error" class="{{ $fieldError }}">{{ $termErrors->first('academic_year') }}</p> @endif
                </div>
                <div>
                    <label for="semester" class="{{ $label }}">Semester</label>
                    <select id="semester" name="semester" required class="login-input"
                        @if ($termErrors->has('semester')) aria-invalid="true" aria-describedby="semester-error" @endif>
                        <option value="" disabled @selected(! ($termErrors->any() && old('semester')))>Choose a semester</option>
                        @foreach ($semesters as $semester)
                            <option value="{{ $semester->value }}" @selected($termErrors->any() && old('semester') === $semester->value)>{{ $semester->label() }}</option>
                        @endforeach
                    </select>
                    @if ($termErrors->has('semester')) <p id="semester-error" class="{{ $fieldError }}">{{ $termErrors->first('semester') }}</p> @endif
                </div>
                <button type="submit" class="{{ $primaryButton }}">Add term</button>
            </form>

            <h3 class="border-t border-border px-5 pt-4 pb-2 text-sm font-semibold">All terms</h3>
            @if ($terms->isEmpty())
                <p class="px-5 pb-5 text-sm text-text-muted">No terms yet.</p>
            @else
                <ul class="divide-y divide-border border-t border-border">
                    @foreach ($terms as $term)
                        <li class="flex flex-wrap items-center justify-between gap-3 px-5 py-3">
                            <span class="text-sm font-semibold">{{ $term->label() }}</span>
                            @if ($term->is_current)
                                <span class="rounded-full bg-primary/10 px-2.5 py-1 text-xs font-semibold text-primary">Current</span>
                            @else
                                <form method="POST" action="{{ route('admin.academic-terms.current', $term) }}"
                                    data-confirm="Set {{ $term->label() }} as current? Students can then only register with a COR for this term.">
                                    @csrf
                                    <button type="submit" class="{{ $smallButton }}">Set as current</button>
                                </form>
                            @endif
                        </li>
                    @endforeach
                </ul>
            @endif
        </section>

        {{-- 4. My account --}}
        <section id="my-account" role="tabpanel" aria-labelledby="tab-my-account" @if ($activeTab !== 'my-account') hidden @endif
            class="overflow-hidden rounded-2xl border border-border bg-surface">
            <div class="px-5 pt-5">
                <h2 id="my-account-heading" class="font-semibold">My account</h2>
                <p class="mt-1 text-sm text-text-muted">Signed in as {{ $user->full_name }} ({{ $user->school_id }}). Re-running the seeder won’t undo these changes.</p>
                @include('admin.settings-message', ['section' => 'account', 'class' => 'mt-4'])
            </div>

            @include('partials.account-email-form', ['routes' => 'admin.settings.account'])

            <form method="POST" action="{{ route('admin.settings.account.password') }}" novalidate data-submit-once class="grid gap-4 border-t border-border px-5 py-5 sm:grid-cols-2">
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

        {{-- 4. System status (read-only) --}}
        <section id="system-status" role="tabpanel" aria-labelledby="tab-system-status" @if ($activeTab !== 'system-status') hidden @endif>
            <div class="flex flex-col gap-6">
                <div class="overflow-hidden rounded-2xl border border-border bg-surface">
                    <div class="px-5 pt-5">
                        <h2 class="font-semibold">System status</h2>
                        <p class="mt-1 text-sm text-text-muted">Push subscribers updates by itself while this tab is open. The other checks are as of when the page loaded; reload to check them again.</p>
                    </div>

                    <ul class="mt-4 divide-y divide-border border-t border-border">
                        @foreach ($checks as $key => $check)
                            @php($isLive = $key === 'push-subscribers')
                            {{-- Push subscribers: resources/js/push-subscribers-live.js asks the server for the count while this tab is open. --}}
                            <li class="grid gap-1.5 px-5 py-4" @if ($isLive) data-push-subscribers-live data-url="{{ route('admin.settings.push-subscribers') }}" @endif>
                                <div class="flex flex-wrap items-center justify-between gap-x-4 gap-y-1.5">
                                    <h3 class="text-sm font-semibold">{{ $check->name }}</h3>
                                    @if ($isLive)
                                        @include('admin.status-badge', ['ok' => true, 'hidden' => ! $check->ok])
                                        @include('admin.status-badge', ['ok' => false, 'hidden' => $check->ok])
                                    @else
                                        @include('admin.status-badge', ['ok' => $check->ok])
                                    @endif
                                </div>
                                <p class="text-sm text-text-muted" @if ($isLive) data-check-summary aria-live="polite" @endif>{{ $check->summary }}</p>
                                @if ($isLive)
                                    <p data-check-fix @if ($check->ok || ! $check->fix) hidden @endif class="text-sm font-medium text-text-primary">{{ $check->fix }}</p>
                                    <p data-live-state class="text-xs text-text-muted empty:hidden data-[tone=error]:font-medium data-[tone=error]:text-red-700 data-[tone=warning]:text-amber-800"></p>
                                @elseif (! $check->ok && $check->fix)
                                    <p class="text-sm font-medium text-text-primary">{{ $check->fix }}</p>
                                @endif
                            </li>
                        @endforeach
                    </ul>
                </div>

                {{-- Turns emergency alerts on or off for this browser (the same control students have). --}}
                @include('partials.push-alerts', ['variant' => 'card'])
            </div>
        </section>
    </div>
@endsection
