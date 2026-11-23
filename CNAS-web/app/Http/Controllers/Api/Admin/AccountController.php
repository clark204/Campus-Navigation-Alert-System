<?php

namespace App\Http\Controllers\Api\Admin;

use App\Enums\UserRole;
use App\Http\Controllers\Admin\AccountController as WebAccountController;
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
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Admin > Accounts for the mobile app (admin only), with the web's requests and rules (AccountAdministration,
 * docs/accounts.md): list with search and filters, one account, create, edit details, trust for alerts, temporary
 * password, faculty or staff, delete after typing the email. Admin accounts can't be edited, reset or deleted here.
 */
class AccountController extends Controller
{
    public function __construct(private AccountAdministration $accounts) {}

    /**
     * ?q= search, ?role=, ?trusted=1, ?page=. Newest first, like the web.
     */
    public function index(Request $request): JsonResponse
    {
        $role = UserRole::tryFrom((string) $request->query('role'));
        $page = WebAccountController::listQuery($role, $request->boolean('trusted'), trim((string) $request->query('q')))
            ->paginate(WebAccountController::PER_PAGE);

        return response()->json([
            'data' => $page->getCollection()->map(fn (User $user) => $this->summary($user))->values(),
            'meta' => [
                'current_page' => $page->currentPage(),
                'last_page' => $page->lastPage(),
                'total' => $page->total(),
                'counts' => User::query()->toBase()->selectRaw('role, count(*) as total')->groupBy('role')->pluck('total', 'role')->map(fn ($count) => (int) $count),
                'trusted_count' => WebAccountController::trusted(User::query())->count(),
            ],
        ]);
    }

    /**
     * What the create and edit forms need: the roles the admin can create and the programs by college.
     */
    public function options(): JsonResponse
    {
        return response()->json([
            'roles' => array_map(fn (UserRole $role) => ['value' => $role->value, 'label' => $role->label()], StoreAccountRequest::ROLES),
            'switchable_roles' => array_map(fn (UserRole $role) => ['value' => $role->value, 'label' => $role->label()], AccountAdministration::SWITCHABLE_ROLES),
            'colleges' => WebAccountController::programsByCollege()
                ->map(fn ($programs, string $college) => [
                    'name' => $college,
                    'programs' => $programs->map(fn (Program $program) => ['code' => $program->code, 'name' => $program->name])->values(),
                ])
                ->values(),
        ]);
    }

    public function show(Request $request, User $user): JsonResponse
    {
        return response()->json(['data' => $this->details($request->user(), $user)]);
    }

    public function store(StoreAccountRequest $request): JsonResponse
    {
        $account = $this->accounts->create(
            $request->safe()->only(['first_name', 'middle_name', 'last_name', 'email', 'school_id', 'program_code', 'password']),
            $request->role(),
            $request->boolean('trusted_for_alerts'),
        );

        return response()->json(['message' => AccountAdministration::createdMessage($account), 'data' => $this->details($request->user(), $account)], 201);
    }

    public function update(UpdateAccountRequest $request, User $user): JsonResponse
    {
        $message = $this->accounts->updateDetails($user, $request->safe()->only(['first_name', 'middle_name', 'last_name', 'email', 'school_id', 'program_code']));

        return response()->json(['message' => $message, 'data' => $this->details($request->user(), $user)]);
    }

    public function trust(Request $request, User $user): JsonResponse
    {
        $request->validate(['trusted' => ['required', 'boolean']]);
        $message = $this->accounts->setTrusted($user, $request->boolean('trusted'));

        return response()->json(['message' => $message, 'data' => $this->details($request->user(), $user)]);
    }

    public function password(SetTemporaryPasswordRequest $request, User $user): JsonResponse
    {
        $message = $this->accounts->setTemporaryPassword($user, $request->validated('password'));

        return response()->json(['message' => $message, 'data' => $this->details($request->user(), $user)]);
    }

    public function role(SwitchRoleRequest $request, User $user): JsonResponse
    {
        $message = $this->accounts->switchRole($user, $request->role());

        return response()->json(['message' => $message, 'data' => $this->details($request->user(), $user)]);
    }

    public function destroy(DeleteAccountRequest $request, User $user): JsonResponse
    {
        return response()->json(['message' => $this->accounts->delete($request->user(), $user)]);
    }

    /**
     * @return array<string, mixed>
     */
    private function summary(User $user): array
    {
        return [
            'id' => $user->id,
            'name' => trim($user->full_name) !== '' ? $user->full_name : $user->email,
            'email' => $user->email,
            'school_id' => $user->school_id,
            'role' => $user->role->value,
            'role_label' => $user->role->label(),
            'college' => $user->program?->college?->name,
            'program' => $user->program?->name,
            'trusted_for_alerts' => $user->isTrustedForAlerts(),
            'created_at' => $user->created_at?->toIso8601String(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function details(User $admin, User $user): array
    {
        $user->loadMissing(['program.college', 'latestLoginActivity']);

        return [
            ...$this->summary($user),
            'first_name' => $user->first_name,
            'middle_name' => $user->middle_name,
            'last_name' => $user->last_name,
            'program_code' => $user->program_code,
            'email_verified' => ! $user->needsEmailVerification(),
            'must_change_password' => (bool) $user->must_change_password,
            'can_be_trusted' => $user->canBeTrustedForAlerts(),
            'can_switch_role' => in_array($user->role, AccountAdministration::SWITCHABLE_ROLES, true),
            'is_admin' => $user->isAdmin(),
            'last_sign_in_at' => $user->latestLoginActivity?->logged_in_at?->toIso8601String(),
            'sign_in_count' => $user->loginActivities()->count(),
            'alerts_sent' => Alert::query()->where('created_by', $user->id)->count(),
            'deletes' => $this->accounts->deletionPreview($user),
            'can_delete' => $this->accounts->canDelete($admin, $user),
        ];
    }
}
