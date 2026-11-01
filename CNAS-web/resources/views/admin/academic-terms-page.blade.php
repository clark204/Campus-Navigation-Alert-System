@extends('layout.admin-layout')

@section('title', 'Academic term')
@section('heading', 'Academic term')

@section('content.admin')
    <div class="mx-auto flex max-w-5xl flex-col gap-6">
        <div>
            <h1 class="text-2xl font-semibold tracking-tight text-primary sm:text-3xl">Academic term</h1>
            <p class="mt-1 text-sm text-text-muted">Students can only register with a COR for the current term.</p>
        </div>

        @if (session('status'))
            <p role="status" class="rounded-xl border border-emerald-200 bg-emerald-50 px-4 py-3 text-sm text-emerald-800">{{ session('status') }}</p>
        @endif

        <section aria-labelledby="current-heading" class="rounded-2xl border border-border bg-surface p-5">
            <h2 id="current-heading" class="text-xs font-semibold text-text-muted">Current term</h2>
            @if ($currentTerm)
                <p class="mt-1 text-xl font-semibold text-primary">{{ $currentTerm->label() }}</p>
                @if ($currentTerm->set_current_at)
                    <p class="mt-1 text-xs text-text-muted">Set {{ $currentTerm->set_current_at->diffForHumans() }}</p>
                @endif
            @else
                <p class="mt-1 text-sm font-semibold text-red-700">None. Registration is closed until you set one.</p>
            @endif
        </section>

        <div class="grid gap-6 lg:grid-cols-[minmax(0,1fr)_minmax(0,1.5fr)]">
            <section aria-labelledby="add-heading" class="rounded-2xl border border-border bg-surface p-5">
                <h2 id="add-heading" class="font-semibold">Add a term</h2>

                <form method="POST" action="{{ route('admin.academic-terms.store') }}" class="mt-4 grid gap-4" novalidate>
                    @csrf

                    <div>
                        <label for="academic_year" class="mb-1.5 block text-xs font-semibold text-text-primary">Academic year</label>
                        <input id="academic_year" name="academic_year" type="text" inputmode="numeric" maxlength="9" required
                            value="{{ old('academic_year') }}" placeholder="e.g. 2026-2027" class="login-input"
                            @error('academic_year') aria-invalid="true" aria-describedby="academic_year-error" @enderror>
                        @error('academic_year') <p id="academic_year-error" class="mt-1 text-xs text-red-600">{{ $message }}</p> @enderror
                    </div>

                    <div>
                        <label for="semester" class="mb-1.5 block text-xs font-semibold text-text-primary">Semester</label>
                        <select id="semester" name="semester" required class="login-input"
                            @error('semester') aria-invalid="true" aria-describedby="semester-error" @enderror>
                            <option value="" disabled @selected(! old('semester'))>Choose a semester</option>
                            @foreach ($semesters as $semester)
                                <option value="{{ $semester->value }}" @selected(old('semester') === $semester->value)>{{ $semester->label() }}</option>
                            @endforeach
                        </select>
                        @error('semester') <p id="semester-error" class="mt-1 text-xs text-red-600">{{ $message }}</p> @enderror
                    </div>

                    <button type="submit"
                        class="rounded-lg bg-primary px-4 py-2.5 text-sm font-semibold text-white transition hover:bg-primary-hover">
                        Add term
                    </button>
                </form>
            </section>

            <section aria-labelledby="terms-heading" class="overflow-hidden rounded-2xl border border-border bg-surface">
                <h2 id="terms-heading" class="px-5 pt-4 pb-3 font-semibold">All terms</h2>

                @if ($terms->isEmpty())
                    <p class="border-t border-border px-5 py-4 text-sm text-text-muted">No terms yet.</p>
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
                                        <button type="submit"
                                            class="rounded-lg border border-border px-3 py-1.5 text-xs font-semibold text-text-primary transition hover:border-primary hover:text-primary">
                                            Set as current
                                        </button>
                                    </form>
                                @endif
                            </li>
                        @endforeach
                    </ul>
                @endif
            </section>
        </div>
    </div>
@endsection
