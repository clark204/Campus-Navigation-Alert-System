<?php

namespace App\Http\Resources;

use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * The signed-in or newly registered user, for the mobile app. Never includes the password
 * or any access token.
 *
 * @mixin User
 */
class UserResource extends JsonResource
{
    /**
     * Respond with the fields at the top level, not under "data".
     *
     * @var string|null
     */
    public static $wrap = null;

    /**
     * email_verified: false until the user enters the emailed code (the app then shows its verify screen). Always
     * true for the admin, who never has to verify. must_change_password: true after the admin set a temporary
     * password; the website asks for a new one before anything else (the app has its own Change password). can_send_alerts:
     * the admin, or faculty and staff the admin trusts (the app shows Send an alert).
     *
     * @return array{id: int, school_id: ?string, first_name: ?string, middle_name: ?string, last_name: ?string, email: string, email_verified: bool, must_change_password: bool, role: string, can_send_alerts: bool, college: array{id: string, name: string}|null, program: array{code: string, name: string}|null}
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'school_id' => $this->school_id,
            'first_name' => $this->first_name,
            'middle_name' => $this->middle_name,
            'last_name' => $this->last_name,
            'email' => $this->email,
            'email_verified' => ! $this->needsEmailVerification(),
            'must_change_password' => (bool) $this->must_change_password,
            'role' => $this->role->value,
            'can_send_alerts' => $this->canSendAlerts(),
            'college' => $this->college === null ? null : [
                'id' => $this->college->id,
                'name' => $this->college->name,
            ],
            'program' => $this->program === null ? null : [
                'code' => $this->program->code,
                'name' => $this->program->name,
            ],
        ];
    }
}
