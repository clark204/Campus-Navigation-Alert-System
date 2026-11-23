<?php

namespace App\Http\Controllers\Admin;

use App\Enums\UserRole;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\DeleteAccountRequest;
use App\Http\Requests\Admin\SetTemporaryPasswordRequest;
use App\Http\Requests\Admin\StoreAccountRequest;
use App\Http\Requests\Admin\SwitchRoleRequest;
use App\Http\Requests\Admin\UpdateAccountRequest;
use App\Models\Alert;
use App\Models\Program;
use App\Models\User;
use App\Services\AccountAdministration;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\View\View;

/**
 * Admin > Accounts (docs/accounts.md): everyone's account, with search and role filters; create faculty, staff and
 * student accounts; trust faculty and staff to send emergency alerts; delete an account after typing its email. The
 * rules are in AccountAdministration, shared with the mobile app (Api\Admin\AccountController).
 */
class AccountController extends Controller
{
    public const PER_PAGE = 10;

    /** Header the live search (resources/js/accounts.js) sends to get only the results part of the page. */
    public const RESULTS_HEADER = 'X-Accounts-Results';

    public function __construct(private AccountAdministration $accounts) {}

    public function index(Request $request): View
    {
        $role = UserRole::tryFrom((string) $request->query('role'));
        $trustedOnly = $request->boolean('trusted');
        $search = trim((string) $request->query('q'));

        $accounts = self::listQuery($role, $trustedOnly, $search)
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
            'trustedCount' => self::trusted(User::query())->count(),
        ]);
    }

    /**
     * The account list with its filters (also the mobile app's): newest first, an account inserted without timestamps
     * last. Search runs on the server (User::scopeSearch), so people's details never go to the browser in bulk.
     *
     * @return Builder<User>
     */
    public static function listQuery(?UserRole $role, bool $trustedOnly, string $search): Builder
    {
        return User::query()
            ->with(['program.college'])
            ->when($role, fn (Builder $query) => $query->where('role', $role))
            ->when($trustedOnly, fn (Builder $query) => self::trusted($query))
            ->when($search !== '', fn (Builder $query) => $query->search($search))
            ->orderByRaw('created_at IS NULL')
            ->latest()
            ->latest('id');
    }

    public function create(): View
    {
        return view('admin.account-create-page', [
            'roles' => StoreAccountRequest::ROLES,
            'programs' => self::programsByCollege(),
        ]);
    }

    public function store(StoreAccountRequest $request): RedirectResponse
    {
        $account = $this->accounts->create(
            $request->safe()->only(['first_name', 'middle_name', 'last_name', 'email', 'school_id', 'program_code', 'password']),
            $request->role(),
            $request->boolean('trusted_for_alerts'),
        );

        return to_route('admin.accounts.show', $account)->with('status', AccountAdministration::createdMessage($account));
    }

    /**
     * "Set temporary password" (docs/accounts.md). Not for admin accounts.
     */
    public function password(SetTemporaryPasswordRequest $request, User $user): RedirectResponse
    {
        return back()->with('status', $this->accounts->setTemporaryPassword($user, $request->validated('password')));
    }

    public function show(Request $request, User $user): View
    {
        $user->loadMissing(['program.college', 'latestLoginActivity']);

        return view('admin.account-page', [
            'account' => $user,
            'signInCount' => $user->loginActivities()->count(),
            'alertsSent' => Alert::query()->where('created_by', $user->id)->count(),
            'deletes' => $this->accounts->deletionPreview($user),
            'canDelete' => $this->accounts->canDelete($request->user(), $user),
        ]);
    }

    public function edit(User $user): View
    {
        abort_if($user->isAdmin(), 403, 'Administrators edit their own details in Settings.');

        return view('admin.account-edit-page', [
            'account' => $user,
            'programs' => self::programsByCollege(),
        ]);
    }

    public function update(UpdateAccountRequest $request, User $user): RedirectResponse
    {
        $status = $this->accounts->updateDetails($user, $request->safe()->only(['first_name', 'middle_name', 'last_name', 'email', 'school_id', 'program_code']));

        return to_route('admin.accounts.show', $user)->with('status', $status);
    }

    public function trust(Request $request, User $user): RedirectResponse
    {
        return back()->with('status', $this->accounts->setTrusted($user, $request->boolean('trusted')));
    }

    /**
     * Switch someone between Faculty and Staff (AccountAdministration::switchRole).
     */
    public function role(SwitchRoleRequest $request, User $user): RedirectResponse
    {
        return back()->with('status', $this->accounts->switchRole($user, $request->role()));
    }

    public function destroy(DeleteAccountRequest $request, User $user): RedirectResponse
    {
        return to_route('admin.accounts.index')->with('status', $this->accounts->delete($request->user(), $user));
    }

    /**
     * Programs for the program picker, grouped by college name.
     *
     * @return Collection<string, Collection<int, Program>>
     */
    public static function programsByCollege(): Collection
    {
        return Program::query()->with('college')->orderBy('name')->get()->groupBy(fn (Program $program) => $program->college?->name ?? 'Other');
    }

    /**
     * Faculty and staff the admin trusts to send alerts.
     *
     * @param  Builder<User>  $query
     * @return Builder<User>
     */
    public static function trusted(Builder $query): Builder
    {
        return $query->whereNotNull('alert_trusted_at')->whereIn('role', [UserRole::Faculty, UserRole::Staff]);
    }
}
