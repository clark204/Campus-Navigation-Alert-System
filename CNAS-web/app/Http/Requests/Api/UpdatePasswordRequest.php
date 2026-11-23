<?php

namespace App\Http\Requests\Api;

use App\Http\Requests\Account\UpdatePasswordRequest as SettingsPasswordRequest;

/**
 * The mobile app's Change password: the same rules and messages as Settings → My account, with the current password
 * checked for the token's user.
 */
class UpdatePasswordRequest extends SettingsPasswordRequest
{
    protected string $passwordGuard = 'sanctum';
}
