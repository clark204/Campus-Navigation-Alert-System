{{-- "Can't access your email?" (from "Forgot password?" and the reset page). There is no self-service recovery for this:
     a COR can be copied or faked, so it can't prove who someone is (docs/registration.md, "Password recovery"). The
     administrator checks a school ID in person, fixes the email (Admin > Accounts > Edit details) and sets a temporary
     password. "Send a message" opens the site footer's concern form (#feedback) with the Account topic picked, so the
     admin sees it in Admin > Concerns and the bell. --}}
@extends('layout.auth-card-layout')

@section('title', 'TCC NAV · Can’t access your email')
@section('aside.title', 'We’ll sort it out in person.')
@section('aside.text', 'Your account is tied to your email. If you can’t open it, the administrator checks your school ID and sets you up again. That way nobody else can take over your account with a copy of your papers.')

@section('card')
    <h2 class="text-2xl font-semibold tracking-tight text-text-primary">Can’t access your email?</h2>
    <p class="mt-2 text-sm text-text-muted">The administrator can change the email on your account and give you a temporary password.</p>

    <ol class="mt-6 space-y-4 text-sm">
        <li class="flex gap-3">
            <span class="grid size-7 shrink-0 place-items-center rounded-full bg-primary text-xs font-bold text-white" aria-hidden="true">1</span>
            <div>
                <p class="font-semibold text-text-primary">Go to the CNAS administrator</p>
                <p class="mt-0.5 text-text-muted">Bring your school ID. Tell them the email you want to use from now on.</p>
            </div>
        </li>
        <li class="flex gap-3">
            <span class="grid size-7 shrink-0 place-items-center rounded-full bg-primary text-xs font-bold text-white" aria-hidden="true">2</span>
            <div>
                <p class="font-semibold text-text-primary">Sign in with the temporary password</p>
                <p class="mt-0.5 text-text-muted">You’ll choose your own password right away, then confirm your new email with a code.</p>
            </div>
        </li>
    </ol>

    <div class="mt-6 rounded-2xl bg-bg-light px-4 py-4">
        <p class="text-sm font-semibold text-text-primary">Contact the administrator</p>
        <div class="mt-3 text-sm">
            @include('partials.contact-details')
        </div>
    </div>

    <a href="{{ route('password.no-email', ['topic' => 'account']) }}#feedback"
        class="mt-5 flex w-full items-center justify-center rounded-xl border border-border px-4 py-3 text-sm font-semibold text-primary transition hover:border-secondary hover:bg-secondary/10">
        Send the administrator a message
    </a>
    <p class="mt-2 text-center text-xs text-text-muted">Say who you are and the email you want to use. Write an email you can open, so the reply reaches you.</p>

    <div class="mt-6 flex flex-wrap justify-center gap-x-4 gap-y-2 border-t border-border pt-5 text-xs">
        <a href="{{ route('password.request') }}" class="font-semibold text-primary underline-offset-4 hover:underline">Back to forgot password</a>
        <a href="{{ route('login') }}" class="font-semibold text-primary underline-offset-4 hover:underline">Sign in</a>
    </div>
@endsection
