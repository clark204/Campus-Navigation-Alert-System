<?php

namespace App\Services;

use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Deleting an account from Admin > Accounts. The database deletes what belongs only to the person (sign-in history,
 * class schedule, saved and recent places, "I'm safe" answers); alerts they sent and concerns they wrote are kept
 * without their name. This also signs them out everywhere, and keeps their devices getting emergency alerts as a
 * guest would (a phone shouldn't go quiet because its owner's account was removed).
 */
class AccountDeletion
{
    public function __construct(private UserSessions $sessions) {}

    /**
     * What deleting this account removes, by what the admin sees: label => count.
     *
     * @return array<string, int>
     */
    public function preview(User $user): array
    {
        return [
            'sign-ins in the history' => $user->loginActivities()->count(),
            'classes in their schedule' => $user->classSchedules()->count(),
            'saved places' => $user->savedRooms()->count(),
            '"I\'m safe" answers' => $user->safetyChecks()->count(),
        ];
    }

    public function delete(User $user): void
    {
        DB::transaction(function () use ($user) {
            $this->sessions->endAll($user);
            // Notifications aren't tied by a foreign key (Laravel's notifiable morph), so they go here.
            $user->notifications()->delete();
            $user->pushSubscriptions()->update(['subscribable_type' => null, 'subscribable_id' => null]);
            $user->delete();
        });
    }
}
