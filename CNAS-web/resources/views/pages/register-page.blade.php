@extends('layout.register-layout', ['step' => 1])

@section('title', 'TCC NAV · Create account')

@section('content.register')
    @if ($currentTerm === null)
        <p role="status" class="mt-4 rounded-xl border border-amber-200 bg-amber-50 px-4 py-3 text-sm text-amber-800">
            {{ \App\Services\RegistrationService::CLOSED_MESSAGE }}
        </p>
    @else
        <p class="mt-4 text-sm text-text-muted">
            Upload your COR for <strong class="font-semibold text-text-primary">{{ $currentTerm->label() }}</strong>.
            Use the PDF you downloaded; we'll read your details from it.
        </p>

        <form method="POST" action="{{ route('register.cor') }}" enctype="multipart/form-data" class="mt-6 grid gap-4" data-submit-once novalidate>
            @csrf

            <div>
                <label for="cor" class="mb-1.5 block text-xs font-semibold text-text-primary">COR (PDF, up to 5 MB)</label>
                <input id="cor" name="cor" type="file" accept="application/pdf,.pdf" required
                    class="login-input file:mr-3 file:rounded-lg file:border-0 file:bg-primary/10 file:px-3 file:py-1.5 file:text-xs file:font-semibold file:text-primary"
                    @error('cor') aria-invalid="true" aria-describedby="cor-error" @enderror>
                @error('cor')
                    <ul id="cor-error" class="mt-1 space-y-0.5 text-xs text-red-600">
                        @foreach ($errors->get('cor') as $corError)
                            <li>
                                {{ $corError }}
                                @if ($corError === \App\Services\RegistrationService::DUPLICATE_STUDENT_ID_MESSAGE)
                                    <a href="#contact" class="font-semibold underline underline-offset-2">See contact details</a>
                                @endif
                            </li>
                        @endforeach
                    </ul>
                @enderror
            </div>

            <div class="rounded-xl border border-border bg-primary/5 px-4 py-3 text-xs leading-5 text-text-muted">
                <p class="font-semibold text-text-primary">Data privacy notice</p>
                <p class="mt-1">
                    Under the Data Privacy Act of 2012 (RA 10173), your COR is used only to verify that you are enrolled at
                    Tagoloan Community College for this term. The file is deleted as soon as it has been read. We keep only
                    your student ID, name, college and email.
                </p>
            </div>

            <div>
                <label class="flex items-start gap-2.5 text-xs text-text-muted">
                    <input type="checkbox" name="consent" value="1" @checked(old('consent')) class="mt-0.5 size-4 rounded accent-primary"
                        @error('consent') aria-invalid="true" aria-describedby="consent-error" @enderror>
                    I have read the data privacy notice and agree to my COR being used to verify my enrollment.
                </label>
                @error('consent') <p id="consent-error" class="mt-1 text-xs text-red-600">{{ $message }}</p> @enderror
            </div>

            <button type="submit"
                class="mt-2 flex w-full items-center justify-center gap-4 rounded-xl bg-primary px-6 py-3.5 text-sm font-bold text-white shadow-lg transition hover:bg-primary-hover disabled:cursor-wait disabled:opacity-70">
                Upload and continue <span aria-hidden="true">→</span>
            </button>
        </form>
    @endif
@endsection
