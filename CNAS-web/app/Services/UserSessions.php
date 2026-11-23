<?php

namespace App\Services;

use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Signs a user out everywhere: their web sessions (database session driver only) and every mobile app token. Used
 * after a password change or reset, a COR recovery, an admin temporary password, and an account deletion.
 */
class UserSessions
{
    /**
     * @param  string|null  $keepSessionId  a web session to keep (the browser that changed its own password).
     * @param  int|null  $keepTokenId  an app token to keep (the phone that changed its own password).
     */
    public function endAll(User $user, ?string $keepSessionId = null, ?int $keepTokenId = null): void
    {
        if (config('session.driver') === 'database') {
            DB::connection(config('session.connection'))
                ->table((string) config('session.table', 'sessions'))
                ->where('user_id', $user->getKey())
                ->when($keepSessionId !== null, fn ($query) => $query->where('id', '!=', $keepSessionId))
                ->delete();
        }

        $user->tokens()->when($keepTokenId !== null, fn ($query) => $query->whereKeyNot($keepTokenId))->delete();
    }
}
