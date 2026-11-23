<?php

namespace App\Services;

use App\Enums\EmailCodePurpose;
use App\Enums\UserRole;
use App\Models\User;
use Illuminate\Support\Str;

/**
 * Admin > Accounts (docs/accounts.md), shared by the web (Admin\AccountController) and the mobile app
 * (Api\Admin\AccountController): create, edit details, temporary password, trust for alerts, faculty or staff, delete.
 * Each change answers with the message to show. Admin accounts are never edited, reset or deleted here: the admin
 * changes their own account in Settings, so the console is never left without an admin.
 */
class AccountAdministration
{
    /** The roles that can be switched into each other (a staff member who also teaches). */
    public const SWITCHABLE_ROLES = [UserRole::Faculty, UserRole::Staff];

    public function __construct(private EmailCodes $codes, private UserSessions $sessions, private AccountDeletion $deletion) {}

    /**
     * A new faculty, staff or student account. The password is temporary: they choose their own at their first sign-in
     * (EnsurePasswordIsChanged). Only faculty and staff can be trusted for alerts.
     *
     * @param  array{first_name: string, middle_name?: ?string, last_name: string, email: string, school_id?: ?string, program_code?: ?string, password: string}  $details
     */
    public function create(array $details, UserRole $role, bool $trustedForAlerts): User
    {
        $account = new User($details);
        $account->forceFill(['role' => $role, 'must_change_password' => true]);
        if ($trustedForAlerts && $account->canBeTrustedForAlerts()) {
            $account->forceFill(['alert_trusted_at' => now()]);
        }
        $account->save();

        return $account;
    }

    public static function createdMessage(User $account): string
    {
        return "{$account->full_name}’s account is ready. Give them their email and the temporary password; they choose their own when they first sign in.";
    }

    /**
     * Save the admin's changes to someone's name, ID, program or email. A new email isn't confirmed by anyone yet, so
     * it becomes unverified: the person gets a code at their next sign-in, and any email change they started is
     * cancelled.
     *
     * @param  array<string, mixed>  $details
     */
    public function updateDetails(User $account, array $details): string
    {
        abort_if($account->isAdmin(), 403, 'Administrators edit their own details in Settings.');

        $account->fill($details);
        $emailChanged = $account->isDirty('email');
        if ($emailChanged) {
            $account->forceFill(['email_verified_at' => null]);
        }
        $account->save();

        if ($emailChanged) {
            $this->codes->forget($account, EmailCodePurpose::ChangeEmail);
            $this->codes->forget($account, EmailCodePurpose::VerifyEmail);
        }

        return $emailChanged
            ? "Saved. {$account->first_name} will confirm {$account->email} with a code the next time they sign in."
            : "{$account->full_name}’s details are saved.";
    }

    /**
     * "Set temporary password": for someone who forgot theirs and can't reset it themselves. They must choose their own
     * at their next sign-in, and every session and app token they had ends now. Not for admin accounts.
     */
    public function setTemporaryPassword(User $account, string $password): string
    {
        abort_if($account->isAdmin(), 403, 'Administrators change their own password in Settings.');

        $account->password = $password;
        $account->forceFill(['must_change_password' => true]);
        $account->setRememberToken(Str::random(60));
        $account->save();
        $this->sessions->endAll($account);

        return "Temporary password set. Give it to {$account->first_name} in person; they’ll choose their own when they sign in. They were signed out everywhere.";
    }

    public function setTrusted(User $account, bool $trusted): string
    {
        abort_unless($account->canBeTrustedForAlerts(), 422, 'Only faculty and staff can be trusted to send alerts.');

        $account->trustForAlerts($trusted);

        return $trusted
            ? "{$account->full_name} can now send emergency alerts from their portal."
            : "{$account->full_name} can no longer send emergency alerts.";
    }

    /**
     * Switch someone between Faculty and Staff. Faculty get "My classes"; staff don't. Their classes and alert trust
     * stay as they are. Other roles can't be switched.
     */
    public function switchRole(User $account, UserRole $role): string
    {
        abort_unless(in_array($account->role, self::SWITCHABLE_ROLES, true), 422, 'Only faculty and staff can be switched here.');

        $account->forceFill(['role' => $role])->save();

        return "{$account->full_name} is now {$account->role->label()}.".($account->role === UserRole::Faculty
            ? ' They can add the classes they teach under My classes.'
            : ' My classes is hidden from them.');
    }

    /**
     * Admin accounts (including your own) can't be deleted here, so the console is never left without an admin.
     */
    public function canDelete(User $admin, User $account): bool
    {
        return ! $account->isAdmin() && ! $account->is($admin);
    }

    /**
     * What deleting the account removes, for the confirm step (only the kinds they have).
     *
     * @return array<string, int>
     */
    public function deletionPreview(User $account): array
    {
        return array_filter($this->deletion->preview($account));
    }

    public function delete(User $admin, User $account): string
    {
        abort_unless($this->canDelete($admin, $account), 403);

        $name = $account->full_name;
        $this->deletion->delete($account);

        return "{$name}’s account was deleted.";
    }
}
