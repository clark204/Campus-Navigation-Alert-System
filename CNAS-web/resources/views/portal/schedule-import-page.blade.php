{{-- Student "My schedule" → Import from COR. Needs $term (the current AcademicTerm or null). --}}
@extends('layout.portal-layout')

@section('title', 'Import from COR')
@section('heading', 'My schedule')

@section('content.portal')
    <div class="mx-auto flex max-w-xl flex-col gap-6">
        <div>
            <a href="{{ route('student.schedule.index') }}" class="text-sm font-semibold text-primary underline-offset-4 hover:underline">My schedule</a>
            <h1 class="mt-2 text-2xl font-semibold tracking-tight text-primary sm:text-3xl">Import from COR</h1>
        </div>

        @if ($term === null)
            <p role="status" class="rounded-xl border border-amber-200 bg-amber-50 px-4 py-3 text-sm text-amber-800">
                {{ \App\Services\ClassSchedules::NO_TERM_MESSAGE }}
            </p>
        @else
            <section aria-label="Import from COR" class="rounded-2xl border border-border bg-surface p-5">
                <p class="text-sm text-text-muted">
                    Upload your own COR for <strong class="font-semibold text-text-primary">{{ $term->label() }}</strong>.
                    Use the PDF you downloaded; your classes are read from it.
                </p>

                <form method="POST" action="{{ route('student.schedule.import.store') }}" enctype="multipart/form-data"
                    class="mt-5 grid gap-4" data-submit-once novalidate>
                    @csrf

                    <div>
                        <label for="cor" class="mb-1.5 block text-xs font-semibold text-text-primary">COR (PDF, up to 5 MB)</label>
                        <input id="cor" name="cor" type="file" accept="application/pdf,.pdf" required
                            class="login-input file:mr-3 file:rounded-lg file:border-0 file:bg-primary/10 file:px-3 file:py-1.5 file:text-xs file:font-semibold file:text-primary"
                            aria-describedby="cor-notes @error('cor') cor-error @enderror"
                            @error('cor') aria-invalid="true" @enderror>
                        @error('cor') <p id="cor-error" class="mt-1 text-xs text-red-600">{{ $message }}</p> @enderror
                    </div>

                    <ul id="cor-notes" class="grid gap-1.5 rounded-xl border border-border bg-primary/5 px-4 py-3 text-xs leading-5 text-text-muted">
                        <li>This replaces the classes that came from an earlier COR.</li>
                        <li><strong class="font-semibold text-text-primary">Classes you changed by hand are kept.</strong></li>
                        <li>The file is deleted as soon as it has been read. Only your classes are saved.</li>
                    </ul>

                    <div class="flex flex-wrap items-center gap-3">
                        <button type="submit" class="inline-flex min-h-11 items-center rounded-lg bg-primary px-5 text-sm font-semibold text-white transition hover:bg-primary-hover disabled:cursor-wait disabled:opacity-70">
                            Import my classes
                        </button>
                        <a href="{{ route('student.schedule.index') }}" class="inline-flex min-h-11 items-center px-2 text-sm font-semibold text-text-muted underline-offset-4 hover:text-primary hover:underline">Cancel</a>
                    </div>
                </form>
            </section>
        @endif
    </div>
@endsection
