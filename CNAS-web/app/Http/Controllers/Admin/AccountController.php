<?php

namespace App\Http\Controllers\Admin;

use App\Enums\EmailCodePurpose;
use App\Enums\UserRole;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\DeleteAccountRequest;
use App\Http\Requests\Admin\StoreAccountRequest;
use App\Http\Requests\Admin\UpdateAccountRequest;
use App\Models\Alert;
use App\Models\Program;
use App\Models\User;
use App\Services\AccountDeletion;
use App\Services\EmailCodes;
use App\Services\UserSessions;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;
use Illuminate\View\View;

/**
 * Admin > Accounts (docs/accounts.md): everyone's account, with search and role filters; create faculty, staff and
 * student accounts; trust faculty and staff to send emergency alerts; delete an account after typing its email.
 */
class AccountController extends Controller
{
    public const PER_PAGE = 10;

    /** Header the live search (resources/js/accounts.js) sends to get only the results part of the page. */
    public const RESULTS_HEADER = 'X-Accounts-Results';

    public function index(Request $request): View
    {
        $role = UserRole::tryFrom((string) $request->query('role'));
        $trustedOnly = $request->boolean('trusted');
        $search = trim((string) $request->query('q'));

        $accounts = User::query()
            ->with(['program.college'])
            ->when($role, fn (Builder $query) => $query->where('role', $role))
            ->when($trustedOnly, fn (Builder $query) => $this->trusted($query))
            ->when($search !== '', fn (Builder $query) => $query->search($search))
            // Newest first; an account inserted without timestamps goes last.
            ->orderByRaw('created_at IS NULL')
            ->latest()
            ->latest('id')
            ->paginate(self::PER_PAGE)
            ->withQueryString();

        // The live search swaps in only the list, its summary and the pages (no layout).
        $results = ['accounts' => $accounts, 'role' => $role, 'trustedOnly' => $trustedOnly, 'search' => $search];
        if ($request->hasHeader(self::RESULTS_HEADER)) {
            return view('admin.accounts-results', $results);
        }

        return view('admin.accounts-page', [
            ...$results,
            'countsByRole' => User::query()->toBase()->selectRaw('role, count(*) as total')->groupBy('role')->pluck('total', 'role'),
            'trustedCount' => $this->trusted(User::query())->count(),
        ]);
    }

    public function create(): View
    {
        return view('admin.account-create-page', [
            'roles' => StoreAccountRequest::ROLES,
            'programs' => $this->programsByCollege(),
        ]);
    }

    public function store(StoreAccountRequest $request): RedirectResponse
    {
        $role = $request->role();
        $account = new User($request->safe()->only(['first_name', 'middle_name', 'last_name', 'email', 'school_id', 'program_code', 'password']));
        // The password is temporary: they choose their own at their first sign-in (EnsurePasswordIsChanged).
        $account->forceFill(['role' => $role, 'must_change_password' => true]);
        if ($request->boolean('trusted_for_alerts') && $account->canBeTrustedForAlerts()) {
            $account->forceFill(['alert_trusted_at' => now()]);
        }
        $account->save();

        return to_route('admin.accounts.show', $account)->with('status', "{$account->full_name}’s account is ready. Give them their email and the temporary password; they choose their own when they first sign in.");
    }

    /**
     * "Set temporary password" (docs/accounts.md): for someone who forgot theirs and can't reset it themselves (faculty
     * and staff mostly; students can also use "Forgot password?" or their COR). They must choose their own at their
     * next sign-in, and every session and app token they had ends now. Not for admin accounts.
     */
    public function password(Request $request, User $user, UserSessions $sessions): RedirectResponse
    {
        abort_if($user->isAdmin(), 403, 'Administrators change their own password in Settings.');

        $validated = $request->validateWithBag('temporaryPassword', [
            'password' => ['required', 'string', Password::defaults()],
        ], [
            'password.required' => 'Type a temporary password, or tap Generate.',
        ]);

        $user->password = $validated['password'];
        $user->forceFill(['must_change_password' => true]);
        $user->setRememberToken(Str::random(60));
        $user->save();
        $sessions->endAll($user);

        return back()->with('status', "Temporary password set. Give it to {$user->first_name} in person; they’ll choose their own when they sign in. They were signed out everywhere.");
    }

    public function show(Request $request, User $user, AccountDeletion $deletion): View
    {
        $user->loadMissing(['program.college', 'latestLoginActivity']);

        return view('admin.account-page', [
            'account' => $user,
            'signInCount' => $user->loginActivities()->count(),
            'alertsSent' => Alert::query()->where('created_by', $user->id)->count(),
            'deletes' => array_filter($deletion->preview($user)),
            'canDelete' => $this->canDelete($request->user(), $user),
        ]);
    }

    public function edit(User $user): View
    {
        abort_if($user->isAdmin(), 403, 'Administrators edit their own details in Settings.');

        return view('admin.account-edit-page', [
            'account' => $user,
            'programs' => $this->programsByCollege(),
        ]);
    }

    /**
     * Save the admin's changes to someone's name, ID, program or email. A new email isn't confirmed by anyone yet, so
     * it becomes unverified: the person gets a code at their next sign-in (registration.md, "Email verification"),
     * and any email change they had started is cancelled.
     */
    public function update(UpdateAccountRequest $request, User $user, EmailCodes $codes): RedirectResponse
    {
        $user->fill($request->safe()->only(['first_name', 'middle_name', 'last_name', 'email', 'school_id', 'program_code']));
        $emailChanged = $user->isDirty('email');

        if ($emailChanged) {
            $user->forceFill(['email_verified_at' => null]);
        }
        $user->save();

        if ($emailChanged) {
            $codes->forget($user, EmailCodePurpose::ChangeEmail);
            $codes->forget($user, EmailCodePurpose::VerifyEmail);
        }

        return to_route('admin.accounts.show', $user)->with('status', $emailChanged
            ? "Saved. {$user->first_name} will confirm {$user->email} with a code the next time they sign in."
            : "{$user->full_name}’s details are saved.");
    }

    public function trust(Request $request, User $user): RedirectResponse
    {
        abort_unless($user->canBeTrustedForAlerts(), 422, 'Only faculty and staff can be trusted to send alerts.');

        $trusted = $request->boolean('trusted');
        $user->trustForAlerts($trusted);

        return back()->with('status', $trusted
            ? "{$user->full_name} can now send emergency alerts from their portal."
            : "{$user->full_name} can no longer send emergency alerts.");
    }

    /**
     * Switch someone between Faculty and Staff (e.g. a staff member who also teaches). Faculty get "My classes";
     * staff don't. Their classes and alert trust stay as they are. Other roles can't be switched here.
     */
    public function role(Request $request, User $user): RedirectResponse
    {
        $switchable = [UserRole::Faculty->value, UserRole::Staff->value];
        abort_unless(in_array($user->role->value, $switchable, true), 422, 'Only faculty and staff can be switched here.');
        $validated = $request->validate(['role' => ['required', Rule::in($switchable)]], ['role.in' => 'Choose faculty or staff.']);

        $user->forceFill(['role' => UserRole::from($validated['role'])])->save();

        return back()->with('status', "{$user->full_name} is now {$user->role->label()}.".($user->role === UserRole::Faculty
            ? ' They can add the classes they teach under My classes.'
            : ' My classes is hidden from them.'));
    }

    public function destroy(DeleteAccountRequest $request, User $user, AccountDeletion $deletion): RedirectResponse
    {
        abort_unless($this->canDelete($request->user(), $user), 403);

        $name = $user->full_name;
        $deletion->delete($user);

        return to_route('admin.accounts.index')->with('status', "{$name}’s account was deleted.");
    }

    /**
     * Programs for the program picker, grouped by college name.
     *
     * @return Collection<string, Collection<int, Program>>
     */
    private function programsByCollege(): Collection
    {
        return Program::query()->with('college')->orderBy('name')->get()->groupBy(fn (Program $program) => $program->college?->name ?? 'Other');
    }

    /**
     * Admin accounts (including your own) can't be deleted here, so the console is never left without an admin.
     */
    private function canDelete(User $admin, User $account): bool
    {
        return ! $account->isAdmin() && ! $account->is($admin);
    }

    /**
     * @param  Builder<User>  $query
     * @return Builder<User>
     */
    private function trusted(Builder $query): Builder
    {
        return $query->whereNotNull('alert_trusted_at')->whereIn('role', [UserRole::Faculty, UserRole::Staff]);
    }
}
