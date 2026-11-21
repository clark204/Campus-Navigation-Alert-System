<?php

namespace App\Http\Controllers\Auth;

use App\Enums\LoginChannel;
use App\Http\Controllers\Controller;
use App\Http\Requests\Registration\CreateAccountRequest;
use App\Http\Requests\Registration\UploadCorWithConsentRequest;
use App\Models\AcademicTerm;
use App\Models\RegistrationDraft;
use App\Services\LoginActivityLogger;
use App\Services\RegistrationService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

/**
 * Web registration in two steps: upload COR, then create the account (the verified COR
 * details read-only, plus email and password). The draft token lives in the session;
 * all the work is in RegistrationService. The new student is signed in and lands on
 * "Verify your email" (EmailVerificationController).
 */
class RegisterController extends Controller
{
    private const DRAFT_TOKEN = 'registration.draft_token';

    public function __construct(private RegistrationService $registrations) {}

    public function create(): View
    {
        return view('pages.register-page', ['currentTerm' => AcademicTerm::current()]);
    }

    public function storeCor(UploadCorWithConsentRequest $request): RedirectResponse
    {
        $previousToken = $request->session()->get(self::DRAFT_TOKEN);

        $started = $this->registrations->startFromCor($request->file('cor'));

        if (is_string($previousToken)) {
            $this->registrations->discard($previousToken);
        }

        $request->session()->put(self::DRAFT_TOKEN, $started->token);

        return redirect()->route('register.confirm');
    }

    public function confirm(Request $request): View|RedirectResponse
    {
        $draft = $this->currentDraft($request);

        if ($draft === null) {
            return $this->restart($request);
        }

        $program = $draft->program();

        return view('pages.register-confirm-page', [
            'cor' => $draft->data,
            'displayName' => $this->displayName($draft->data),
            'collegeName' => $program?->college?->name,
            'programName' => $program?->name,
            'meetingCount' => count($draft->data['meetings'] ?? []),
            // Reuse the key after a failed submit so a retry is still the same request.
            'idempotencyKey' => old('idempotency_key', (string) Str::uuid()),
        ]);
    }

    public function store(CreateAccountRequest $request, LoginActivityLogger $activity): RedirectResponse
    {
        $token = $request->session()->get(self::DRAFT_TOKEN);

        if (! is_string($token)) {
            return $this->restart($request);
        }

        try {
            $user = $this->registrations->complete($token, $request->validated());
        } catch (ValidationException $exception) {
            if (Arr::has($exception->errors(), 'draft_token')) {
                return $this->restart($request);
            }

            if (Arr::has($exception->errors(), 'student_id')) {
                // Someone registered this student ID after the COR was checked.
                return $this->restart($request, RegistrationService::DUPLICATE_STUDENT_ID_MESSAGE);
            }

            // Name or email problems: back to the form, which reopens "Edit name" when needed.
            throw $exception;
        }

        $request->session()->forget(self::DRAFT_TOKEN);

        // Signed in straight away, so the student can enter the code that RegistrationService just emailed.
        Auth::guard('web')->login($user);
        $request->session()->regenerate();
        $login = $activity->recordLogin($user, LoginChannel::Web, $request);
        $request->session()->put(LoginActivityLogger::SESSION_KEY, $login->id);

        return redirect()->route('email.verify')->with('status', "Account created. We sent a 6-digit code to {$user->email}.");
    }

    private function currentDraft(Request $request): ?RegistrationDraft
    {
        $token = $request->session()->get(self::DRAFT_TOKEN);

        return is_string($token) ? $this->registrations->findDraft($token) : null;
    }

    /**
     * "First M. Last" from the parser's split, e.g. "Pedro Jose L. Ramos".
     *
     * @param  array{first_name: ?string, middle_name: ?string, last_name: ?string}  $cor
     */
    private function displayName(array $cor): string
    {
        $middle = $cor['middle_name'];

        if ($middle !== null && mb_strlen($middle) === 1) {
            $middle .= '.';
        }

        return implode(' ', array_filter([$cor['first_name'], $middle, $cor['last_name']]));
    }

    private function restart(Request $request, ?string $message = null): RedirectResponse
    {
        $hadDraft = $request->session()->has(self::DRAFT_TOKEN);
        $request->session()->forget(self::DRAFT_TOKEN);

        $message ??= $hadDraft ? 'Your registration expired. Please upload your COR again.' : null;
        $redirect = redirect()->route('register');

        return $message === null ? $redirect : $redirect->withErrors(['cor' => $message]);
    }
}
